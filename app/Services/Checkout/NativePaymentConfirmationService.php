<?php

namespace App\Services\Checkout;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NativePaymentConfirmationService
{
    public function confirm(
        Order $order,
        ?string $stripeSessionId = null,
        ?string $paypalOrderId = null,
    ): Order {
        $order->loadMissing('team', 'cart');

        if ($order->status === 'paid') {
            return $order;
        }

        $provider = (string) data_get($order->metadata, 'payment_provider', '');

        return match ($provider) {
            'stripe' => $this->confirmStripe($order, $stripeSessionId),
            'paypal' => $this->confirmPayPal($order, $paypalOrderId),
            default => abort(422, 'This order does not have a native payment provider configured.'),
        };
    }

    public function markOrderPaid(
        Order $order,
        string $provider,
        string $paymentReference,
        string $paidVia = 'return_url',
    ): Order {
        if ($order->status === 'paid') {
            return $order;
        }

        $order->update([
            'status' => 'paid',
            'payment_reference' => $paymentReference ?: $order->payment_reference,
            'metadata' => array_merge((array) ($order->metadata ?? []), [
                'payment_provider' => $provider,
                'paid_confirmed_at' => now()->toIso8601String(),
                'paid_via' => $paidVia,
            ]),
        ]);

        $order->cart?->update([
            'status' => 'checked_out',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        Log::info('Native checkout order marked paid', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'provider' => $provider,
        ]);

        return $order->refresh();
    }

    protected function confirmStripe(Order $order, ?string $sessionId): Order
    {
        $settings = (array) data_get($order->team?->settings, 'integrations.stripe', []);
        $secretKey = trim((string) ($settings['secret_key'] ?? ''));

        if ($secretKey === '') {
            abort(422, 'Stripe is not configured for this store.');
        }

        $sessionId = $sessionId
            ?: (string) $order->payment_reference
            ?: (string) data_get($order->metadata, 'payment_session.provider_session_id', '');

        if ($sessionId === '') {
            abort(422, 'Missing Stripe session. Return from Stripe checkout or try paying again.');
        }

        $response = Http::withToken($secretKey)
            ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");

        if (! $response->successful()) {
            abort(422, 'Could not verify Stripe payment.');
        }

        $paymentStatus = (string) $response->json('payment_status', '');

        if ($paymentStatus !== 'paid') {
            abort(422, 'Stripe payment is not completed yet.');
        }

        $metadataOrderId = (int) $response->json('metadata.order_id', 0);

        if ($metadataOrderId > 0 && $metadataOrderId !== (int) $order->id) {
            abort(422, 'Stripe session does not match this order.');
        }

        return $this->markOrderPaid($order, 'stripe', $sessionId);
    }

    protected function confirmPayPal(Order $order, ?string $paypalOrderId): Order
    {
        $settings = (array) data_get($order->team?->settings, 'integrations.paypal', []);
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $clientSecret = trim((string) ($settings['client_secret'] ?? ''));
        $mode = (string) ($settings['mode'] ?? 'sandbox');
        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        if ($clientId === '' || $clientSecret === '') {
            abort(422, 'PayPal is not configured for this store.');
        }

        $paypalOrderId = $paypalOrderId
            ?: (string) $order->payment_reference
            ?: (string) data_get($order->metadata, 'payment_session.provider_session_id', '');

        if ($paypalOrderId === '') {
            abort(422, 'Missing PayPal order reference. Return from PayPal checkout or try paying again.');
        }

        $accessToken = $this->paypalAccessToken($baseUrl, $clientId, $clientSecret);

        $captureResponse = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($baseUrl.'/v2/checkout/orders/'.$paypalOrderId.'/capture');

        if ($captureResponse->status() === 422) {
            $details = $captureResponse->json('details.0.issue', '');

            if ($details === 'ORDER_ALREADY_CAPTURED') {
                return $this->markOrderPaid($order, 'paypal', $paypalOrderId);
            }
        }

        if (! $captureResponse->successful()) {
            $status = (string) $captureResponse->json('status', '');

            if (in_array($status, ['COMPLETED', 'APPROVED'], true)) {
                return $this->markOrderPaid($order, 'paypal', $paypalOrderId);
            }

            abort(422, 'PayPal could not capture this payment.');
        }

        $status = (string) $captureResponse->json('status', '');

        if (! in_array($status, ['COMPLETED', 'APPROVED'], true)) {
            abort(422, 'PayPal payment is not completed yet.');
        }

        $customId = (int) data_get($captureResponse->json(), 'purchase_units.0.payments.captures.0.custom_id', 0);

        if ($customId > 0 && $customId !== (int) $order->id) {
            $customId = (int) data_get($captureResponse->json(), 'purchase_units.0.custom_id', 0);
        }

        if ($customId > 0 && $customId !== (int) $order->id) {
            abort(422, 'PayPal order does not match this checkout.');
        }

        $captureId = (string) data_get(
            $captureResponse->json(),
            'purchase_units.0.payments.captures.0.id',
            $paypalOrderId,
        );

        return $this->markOrderPaid($order, 'paypal', $captureId);
    }

    protected function paypalAccessToken(string $baseUrl, string $clientId, string $clientSecret): string
    {
        $tokenResponse = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post($baseUrl.'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $tokenResponse->successful()) {
            abort(422, 'PayPal could not authenticate this store.');
        }

        return (string) $tokenResponse->json('access_token');
    }
}
