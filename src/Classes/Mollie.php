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
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Dashed\DashedEcommerceCore\Classes\ShoppingCart;
use Dashed\DashedEcommerceCore\Models\PaymentMethod;
use Dashed\DashedEcommerceCore\Classes\PaymentMethods;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryFolder;

class Mollie
{
    /**
     * Lightweight health check for the IntegrationsDashboard. Does NOT call
     * the Mollie API on every dashboard render (that would create test
     * payments) — it inspects the local config + last-error Customsetting.
     */
    public static function healthCheck(?string $siteId = null): \Dashed\DashedCore\Integrations\IntegrationHealth
    {
        $site = Sites::get($siteId);
        $apiKey = Customsetting::get('mollie_api_key', $site['id']);

        if (empty($apiKey)) {
            return \Dashed\DashedCore\Integrations\IntegrationHealth::misconfigured('API key ontbreekt');
        }

        $error = Customsetting::get('mollie_connection_error', $site['id']);
        if (! empty($error)) {
            return \Dashed\DashedCore\Integrations\IntegrationHealth::failing((string) $error);
        }

        return \Dashed\DashedCore\Integrations\IntegrationHealth::ok();
    }

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

        // mollie-api-php v3 (laravel-mollie v4) heeft allActive() vervangen door allEnabled().
        $methods = \Mollie\Laravel\Facades\Mollie::api()->methods;
        $allPaymentMethods = method_exists($methods, 'allEnabled')
            ? $methods->allEnabled()->getArrayCopy()
            : $methods->allActive()->getArrayCopy();

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
            $paymentMethod->active = false;

            foreach (Locales::getLocales() as $locale) {
                $paymentMethod->setTranslation('name', $locale['id'], $allPaymentMethod->description);
            }

            $paymentMethod->save();

            PaymentMethods::notifyAdminsOfNewPaymentMethod($paymentMethod);
        }
    }

    public static function startTransaction(OrderPayment $orderPayment): array
    {
        $orderPayment->psp = 'mollie';
        $orderPayment->save();

        config([
            'mollie.key' => Customsetting::get('mollie_api_key'),
        ]);

        $paymentData = self::getPaymentData($orderPayment);
        // Bestaat pas vanaf ec-core v4.134.0; dit pakket vereist ec-core niet.
        if (method_exists($orderPayment, 'recordPspRequest')) {
            $orderPayment->recordPspRequest($paymentData);
        }

        $payment = \Mollie\Laravel\Facades\Mollie::api()->payments->create($paymentData);

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

        if (self::shouldSendOrderLines($orderPayment)) {
            $lines = self::getPaymentLines($orderPayment);

            if ($lines !== null) {
                $paymentData['lines'] = $lines;
            }
        }

        return $paymentData;
    }

    public static function shouldSendOrderLines(OrderPayment $orderPayment): bool
    {
        $siteId = $orderPayment->order?->site_id;

        return (bool) Customsetting::get('mollie_send_order_lines', $siteId ? (string) $siteId : null);
    }

    /**
     * Orderregels voor de Mollie Payments API. Methodes als Klarna, in3,
     * Riverty en Billie weigeren een betaling zonder `lines`.
     *
     * Mollie eist dat de som van alle `totalAmount` exact gelijk is aan het
     * betaalbedrag. Lukt dat niet, dan is een betaling zonder regels beter dan
     * een betaling met regels die niet kloppen: dan geeft deze methode null
     * terug en blijft de payload zoals hij was.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected static function getPaymentLines(OrderPayment $orderPayment): ?array
    {
        $order = $orderPayment->order;

        if (! $order) {
            return null;
        }

        $targetCents = (int) round(((float) $orderPayment->amount) * 100);

        if ($targetCents <= 0) {
            return null;
        }

        $lines = [];
        $subtotalCents = 0;

        foreach ($order->orderProducts()->get() as $orderProduct) {
            $totalCents = (int) round(((float) $orderProduct->price) * 100);

            // Een creditregel (negatief bedrag) maakt de hele set onbruikbaar:
            // Mollie accepteert binnen een gewone betaling geen regel onder nul.
            if ($totalCents < 0) {
                return null;
            }

            $lines[] = [
                'totalCents' => $totalCents,
                'quantity' => max(1, (int) $orderProduct->quantity),
                'vatRate' => round((float) ($orderProduct->vat_rate ?? 0), 2),
                'description' => self::getLineDescription($orderProduct),
                'type' => self::getLineType($orderProduct),
                'sku' => self::getLineSku($orderProduct),
            ];

            $subtotalCents += $totalCents;
        }

        if (! $lines || $subtotalCents <= 0) {
            return null;
        }

        // De korting staat op de order en niet op de regels, dus het verschil
        // tussen de regels en het betaalbedrag wordt naar rato verdeeld. Een
        // betaalbedrag boven het regeltotaal (een deelbetaling van een groter
        // bedrag bestaat niet, maar een aanbetaling wel) is niet te verdelen.
        $differenceCents = $subtotalCents - $targetCents;

        if ($differenceCents < 0) {
            return null;
        }

        $remainingCents = $differenceCents;

        foreach ($lines as $index => $line) {
            if ($remainingCents <= 0) {
                break;
            }

            // Naar beneden afronden, zodat de verdeling het verschil nooit
            // overschrijdt; de afrondingscenten gaan in de ronde hierna.
            $share = (int) floor($differenceCents * $line['totalCents'] / $subtotalCents);
            $share = min($share, $line['totalCents'], $remainingCents);

            $lines[$index]['totalCents'] -= $share;
            $remainingCents -= $share;
        }

        foreach ($lines as $index => $line) {
            if ($remainingCents <= 0) {
                break;
            }

            $share = min($lines[$index]['totalCents'], $remainingCents);

            $lines[$index]['totalCents'] -= $share;
            $remainingCents -= $share;
        }

        if ($remainingCents > 0) {
            return null;
        }

        $paymentLines = [];

        foreach ($lines as $line) {
            $quantity = $line['quantity'];
            $totalCents = $line['totalCents'];

            // Mollie rekent totalAmount = unitPrice * quantity - discountAmount.
            // De eenheidsprijs wordt daarom naar boven afgerond en wat er te
            // veel in zit gaat als korting op de regel mee.
            $unitPriceCents = (int) ceil($totalCents / $quantity);
            $discountCents = ($unitPriceCents * $quantity) - $totalCents;

            // Mollie wil altijd btw-inclusieve bedragen met
            // vatAmount = totalAmount * vatRate / (100 + vatRate).
            $vatRate = $line['vatRate'];
            $vatCents = $totalCents - (int) round($totalCents * 100 / (100 + $vatRate));

            $paymentLine = [
                'description' => $line['description'],
                'quantity' => $quantity,
                'unitPrice' => self::formatAmount($unitPriceCents),
                'totalAmount' => self::formatAmount($totalCents),
                'vatRate' => number_format($vatRate, 2, '.', ''),
                'vatAmount' => self::formatAmount($vatCents),
                'type' => $line['type'],
            ];

            if ($discountCents > 0) {
                $paymentLine['discountAmount'] = self::formatAmount($discountCents);
            }

            if ($line['sku']) {
                $paymentLine['sku'] = $line['sku'];
            }

            $paymentLines[] = $paymentLine;
        }

        return $paymentLines;
    }

    protected static function formatAmount(int $cents): array
    {
        return [
            'currency' => 'EUR',
            'value' => number_format($cents / 100, 2, '.', ''),
        ];
    }

    protected static function getLineDescription(OrderProduct $orderProduct): string
    {
        $description = $orderProduct->name ?: ($orderProduct->product->name ?? '');

        return mb_substr(trim($description) ?: __('Product'), 0, 255);
    }

    protected static function getLineType(OrderProduct $orderProduct): string
    {
        return match ($orderProduct->sku) {
            'shipping_costs' => 'shipping_fee',
            'payment_costs', 'product_costs' => 'surcharge',
            default => 'physical',
        };
    }

    protected static function getLineSku(OrderProduct $orderProduct): ?string
    {
        $sku = (string) $orderProduct->sku;

        if (! $sku || in_array($sku, ['shipping_costs', 'payment_costs', 'product_costs'], true)) {
            return null;
        }

        return mb_strlen($sku) > 64 ? null : $sku;
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

        $email = $order->email;

        if (! filled($email)) {
            return null;
        }

        $fullBillingAddress = [
            'streetAndNumber' => trim(
                ($order->invoice_street ?: $order->street) . ' ' .
                ($order->invoice_house_nr ?: $order->house_nr)
            ),
            'postalCode' => $order->invoice_zip_code ?: $order->zip_code,
            'city' => $order->invoice_city ?: $order->city,
            'country' => Countries::getCountryIsoCode($order->invoice_country ?: $order->country),
            'givenName' => $order->first_name,
            'familyName' => $order->last_name,
        ];

        $isComplete = collect($fullBillingAddress)->every(fn ($value) => filled($value));

        if ($isComplete) {
            return [
                ...$fullBillingAddress,
                'email' => $email,
            ];
        }

        return [
            'email' => $email,
        ];
    }
}
