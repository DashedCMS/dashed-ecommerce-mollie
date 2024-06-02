<?php

namespace Dashed\DashedEcommerceMollie\Classes;

use Dashed\DashedCore\Classes\Locales;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Models\PaymentMethod;
use Dashed\DashedTranslations\Models\Translation;
use Exception;
use Illuminate\Support\Facades\Storage;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryFolder;
use RalphJSmit\Filament\MediaLibrary\Media\Models\MediaLibraryItem;

class Mollie
{
    public static function isConnected($siteId = null)
    {
        $site = Sites::get($siteId);
        config(['mollie.key' => Customsetting::get('mollie_api_key', $site['id'])]);

        try {
            $payment = \Mollie\Laravel\Facades\Mollie::api()->payments->create([
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

    public static function syncPaymentMethods($siteId = null)
    {
        $site = Sites::get($siteId);

        if (! Customsetting::get('mollie_connected', $site['id'])) {
            return;
        }

        config(['mollie.key' => Customsetting::get('mollie_api_key', $site['id'])]);
        $allPaymentMethods = \Mollie\Laravel\Facades\Mollie::api()->methods()->allActive()->getArrayCopy();

        foreach ($allPaymentMethods as $allPaymentMethod) {
            if (! PaymentMethod::where('psp', 'mollie')->where('psp_id', $allPaymentMethod->id)->count()) {
                $image = file_get_contents($allPaymentMethod->image->size2x);
                $imagePath = '/dashed/payment-methods/mollie/' . $allPaymentMethod->id . '.png';
                Storage::disk('dashed')->put($imagePath, $image);

                $folder = MediaLibraryFolder::where('name', 'mollie')->first();
                if (! $folder) {
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
                $paymentMethod->available_from_amount = $allPaymentMethod->minimumAmount->value ?: 0;
                $paymentMethod->psp = 'mollie';
                $paymentMethod->psp_id = $allPaymentMethod->id;
                $paymentMethod->image = $filamentMediaLibraryItem->id;
                foreach (Locales::getLocales() as $locale) {
                    $paymentMethod->setTranslation('name', $locale['id'], $allPaymentMethod->description);
                }
                $paymentMethod->save();
            }
        }
    }

    public static function startTransaction(OrderPayment $orderPayment)
    {
        $orderPayment->psp = 'mollie';
        $orderPayment->save();

        config(['mollie.key' => Customsetting::get('mollie_api_key')]);
        $payment = \Mollie\Laravel\Facades\Mollie::api()->payments->create([
            'amount' => [
                'currency' => 'EUR',
                'value' => number_format($orderPayment->amount, 2, '.', ''),
            ],
            'description' => Translation::get('payment-description', 'payments', 'Order #:orderId:', 'text', [
                'orderId' => $orderPayment->order->id,
            ]),
            'redirectUrl' => route('dashed.frontend.checkout.complete') . '?orderId=' . $orderPayment->order->hash . '&paymentId=' . $orderPayment->hash,
            'webhookUrl' => route('dashed.frontend.checkout.exchange'),
            'method' => $orderPayment->paymentMethod ? $orderPayment->paymentMethod->psp_id : null,
            'metadata' => [
                'order_id' => Translation::get('payment-description', 'payments', 'Order #:orderId:', 'text', [
                    'orderId' => $orderPayment->order->id,
                ]),
            ],
        ]);

        $orderPayment->psp_id = $payment->id;
        $orderPayment->save();

        return [
            'transaction' => $payment,
            'redirectUrl' => $payment->getCheckoutUrl(),
        ];
    }

    public static function getOrderStatus(OrderPayment $orderPayment)
    {
        config(['mollie.key' => Customsetting::get('mollie_api_key')]);
        $payment = \Mollie\Laravel\Facades\Mollie::api()->payments->get($orderPayment->psp_id);

        if ($payment->isPaid()) {
            return 'paid';
        } elseif ($payment->isCanceled()) {
            return 'cancelled';
        } else {
            return 'pending';
        }
    }
}
