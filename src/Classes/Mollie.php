<?php

namespace Dashed\DashedEcommerceMollie\Classes;

use Exception;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Classes\Locales;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Classes\Countries;
use Dashed\DashedTranslations\Models\Translation;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Classes\ShoppingCart;
use Dashed\DashedEcommerceCore\Models\PaymentMethod;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;

class Mollie
{
    public static function isConnected($siteId = null): bool
    {
        $site = Sites::get($siteId);

        config([
            'mollie.key' => Customsetting::get('mollie_api_key', $site['id']),
        ]);

        try {
            \Mollie\Laravel\Facades\Mollie::api()->payments->create([
                'amount' => [
                    'currency' => 'EUR',
                    'value' => '10.00',
                ],
                'description' => 'Connectiecheck testbestelling',
                'redirectUrl' => url('/'),
                'webhookUrl' => url('/'),
            ]);

            Customsetting::set('mollie_connection_error', '', $site['id']);

            return true;
        } catch (Exception $e) {
            Customsetting::set('mollie_connection_error', $e->getMessage(), $site['id']);

            return false;
        }
    }

    public static function syncPaymentMethods($siteId = null): void
    {
        $site = Sites::get($siteId);

        if (! Customsetting::get('mollie_connected', $site['id'])) {
            return;
        }

        config([
            'mollie.key' => Customsetting::get('mollie_api_key', $site['id']),
        ]);

        $allPaymentMethods = \Mollie\Laravel\Facades\Mollie::api()->methods->allActive()->getArrayCopy();

        foreach ($allPaymentMethods as $allPaymentMethod) {
            if (PaymentMethod::where('psp', 'mollie')->where('psp_id', $allPaymentMethod->id)->exists()) {
                continue;
            }

            $image = file_get_contents($allPaymentMethod->image->size2x);
            $imagePath = '/dashed/payment-methods/mollie/' . $allPaymentMethod->id . '.png';

            Storage::disk('dashed')->put($imagePath, $image);

            $folder = MediaLibraryFolder::where('name', 'mollie')->first();

            if (! $folder) {
                $folder = new MediaLibraryFolder();
                $folder->name = 'mollie';
                $folder->save();
            }

            $filamentMediaLibraryItem = new MediaLibraryItem();
            $filamentMediaLibraryItem->uploaded_by_user_id = null;
            $filamentMediaLibraryItem->folder_id = $folder->id;
            $filamentMediaLibraryItem->save();

            $filamentMediaLibraryItem
                ->addMediaFromDisk($imagePath, 'dashed')
                ->toMediaCollection($filamentMediaLibraryItem->getMediaLibraryCollectionName());

            $paymentMethod = new PaymentMethod();
            $paymentMethod->site_id = $site['id'];
            $paymentMethod->available_from_amount = $allPaymentMethod->minimumAmount->value ?? 0;
            $paymentMethod->psp = 'mollie';
            $paymentMethod->psp_id = $allPaymentMethod->id;
            $paymentMethod->image = $filamentMediaLibraryItem->id;

            foreach (Locales::getLocales() as $locale) {
                $paymentMethod->setTranslation('name', $locale['id'], $allPaymentMethod->description);
            }

            $paymentMethod->save();
        }
    }

    public static function startTransaction(OrderPayment $orderPayment): array
    {
        $orderPayment->psp = 'mollie';
        $orderPayment->save();

        config([
            'mollie.key' => Customsetting::get('mollie_api_key'),
        ]);

        $payment = \Mollie\Laravel\Facades\Mollie::api()->payments->create(
            self::getPaymentData($orderPayment)
        );

        $orderPayment->psp_id = $payment->id;
        $orderPayment->save();

        return [
            'transaction' => $payment,
            'redirectUrl' => $payment->getCheckoutUrl(),
        ];
    }

    public static function getPaymentData(OrderPayment $orderPayment): array
    {
        $paymentData = [
            'amount' => [
                'currency' => 'EUR',
                'value' => number_format($orderPayment->amount, 2, '.', ''),
            ],
            'description' => Translation::get('payment-description', 'payments', 'Order #:orderId:', 'text', [
                'orderId' => $orderPayment->order->id,
            ]),
            'redirectUrl' => url(ShoppingCart::getCompleteUrl()) . '?orderId=' . $orderPayment->order->hash . '&paymentId=' . $orderPayment->hash,
            'cancelUrl' => url(ShoppingCart::getCheckoutUrl()),
            'webhookUrl' => route('dashed.frontend.checkout.exchange'),
            'method' => $orderPayment->paymentMethod?->psp_id,
            'metadata' => [
                'order_id' => Translation::get('payment-description', 'payments', 'Order #:orderId:', 'text', [
                    'orderId' => $orderPayment->order->id,
                ]),
            ],
        ];

        $billingAddress = self::getBillingAddress($orderPayment);

        if ($billingAddress !== null) {
            $paymentData['billingAddress'] = $billingAddress;
        }

        return $paymentData;
    }

    public static function getOrderStatus(OrderPayment $orderPayment): string
    {
        config([
            'mollie.key' => Customsetting::get('mollie_api_key'),
        ]);

        $payment = \Mollie\Laravel\Facades\Mollie::api()->payments->get($orderPayment->psp_id);

        if ($payment->isPaid()) {
            return 'paid';
        }

        if ($payment->isCanceled()) {
            return 'cancelled';
        }

        if ($payment->isFailed()) {
            return 'failed';
        }

        return 'pending';
    }

    public static function getPayment(OrderPayment $orderPayment)
    {
        config([
            'mollie.key' => Customsetting::get('mollie_api_key'),
        ]);

        return \Mollie\Laravel\Facades\Mollie::api()->payments->get($orderPayment->psp_id);
    }

    protected static function getBillingAddress(OrderPayment $orderPayment): ?array
    {
        $order = $orderPayment->order;

        $billingAddress = [
            'streetAndNumber' => trim(
                ($order->invoice_street ?: $order->street) . ' ' .
                ($order->invoice_house_nr ?: $order->house_nr)
            ),
            'postalCode' => $order->invoice_zip_code ?: $order->zip_code,
            'city' => $order->invoice_city ?: $order->city,
            'country' => Countries::getCountryIsoCode($order->invoice_country ?: $order->country),
            'givenName' => $order->first_name,
            'familyName' => $order->last_name,
            'email' => $order->email,
        ];

        foreach ($billingAddress as $value) {
            if (! filled($value)) {
                return null;
            }
        }

        return $billingAddress;
    }
}
