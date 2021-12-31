<?php

namespace Qubiqx\QcommerceEcommerceMollie\Classes;

namespace Qubiqx\Qcommerce\Classes;

use Exception;
use Illuminate\Support\Facades\Cache;
use Qubiqx\QcommerceCore\Classes\Sites;
use Qubiqx\QcommerceCore\Models\Customsetting;
use Qubiqx\QcommerceCore\Models\Translation;
use Qubiqx\QcommerceEcommerceCore\Models\OrderPayment;

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

            return true;
        } catch (Exception $e) {
            Customsetting::set('mollie_connection_error', $e->getMessage(), $site['id']);

            return false;
        }
    }

    public static function getPaymentMethods($siteId = null, $cache = true)
    {
        $site = Sites::get($siteId);

        if (! Customsetting::get('mollie_connected', $site['id'])) {
            return;
        }

        if (! $cache) {
            Cache::forget('mollie-payment-methods-' . $site['id']);
        }

        $result = Cache::remember('mollie-payment-methods-' . $site['id'], 60 * 60 * 24, function () use ($site) {
            config(['mollie.key' => Customsetting::get('mollie_api_key', $site['id'])]);

            $result = \Mollie\Laravel\Facades\Mollie::api()->methods()->allActive()->getArrayCopy();
            foreach ($result as $paymentMethod) {
                $paymentMethod->active = Customsetting::get('mollie_payment_method_' . $paymentMethod->id, $site['id'], 0) ? true : false;
                $paymentMethod->costs = Customsetting::get('mollie_payment_method_costs_' . $paymentMethod->id, $site['id'], 0);
            }

            return $result;
        });

        return $result;
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
            'redirectUrl' => route('qcommerce.frontend.checkout.complete') . '?orderId=' . $orderPayment->order->hash . '&paymentId=' . $orderPayment->hash,
            'webhookUrl' => route('qcommerce.frontend.checkout.exchange'),
            'method' => str_replace('mollie_', '', $orderPayment->psp_payment_method_id),
            'metadata' => [
                'order_id' => Translation::get('payment-description', 'payments', 'Order #:orderId:', 'text', [
                    'orderId' => $orderPayment->order->id,
                ]),
            ],
        ]);

        $orderPayment->psp_id = $payment->id;
        $orderPayment->save();

        return $payment;
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
