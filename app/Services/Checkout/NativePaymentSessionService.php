<?php

namespace App\Services\Checkout;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

class NativePaymentSessionService
{
    /**
     * @return array{provider: string, checkout_url: string, provider_session_id: string}
     */
    public function createSession(Order $order): array
    {
        $provider = (string) data_get($order->metadata, 'payment_provider', '');

        return match ($provider) {
            'stripe' => $this->createStripeSession($order),
            'paypal' => $this->createPayPalSession($order),
            default => abort(422, 'Native payment provider is not configured for this order.'),
        };
    }

    /**
     * @return array{provider: string, checkout_url: string, provider_session_id: string}
     */
    protected function createStripeSession(Order $order): array
    {
        $settings = (array) data_get($order->team?->settings, 'integrations.stripe', []);
        $secretKey = trim((string) ($settings['secret_key'] ?? ''));

        if ($secretKey === '') {
            abort(422, 'Stripe is not configured for this store.');
        }

        $payload = [
            'mode' => 'payment',
            'success_url' => route('checkout.show', [
                'order' => $order,
                'token' => data_get($order->metadata, 'checkout_token'),
            ]).'?payment=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('checkout.show', [
                'order' => $order,
                'token' => data_get($order->metadata, 'checkout_token'),
            ]).'?payment=cancelled',
            'client_reference_id' => $order->order_number,
            'metadata[order_id]' => (string) $order->id,
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => strtolower($order->currency),
            'line_items[0][price_data][unit_amount]' => (string) (int) round(((float) $order->total_amount) * 100),
            'line_items[0][price_data][product_data][name]' => 'Order '.$order->order_number,
        ];

        if ($order->customer_email) {
            $payload['customer_email'] = $order->customer_email;
        }

        $response = Http::asForm()
            ->withToken($secretKey)
            ->post('https://api.stripe.com/v1/checkout/sessions', $payload);

        if (! $response->successful()) {
            abort(422, 'Stripe could not start checkout: '.$response->json('error.message', 'Unknown Stripe error.'));
        }

        return [
            'provider' => 'stripe',
            'checkout_url' => (string) $response->json('url'),
            'provider_session_id' => (string) $response->json('id'),
        ];
    }

    /**
     * @return array{provider: string, checkout_url: string, provider_session_id: string}
     */
    protected function createPayPalSession(Order $order): array
    {
        $settings = (array) data_get($order->team?->settings, 'integrations.paypal', []);
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $clientSecret = trim((string) ($settings['client_secret'] ?? ''));
        $mode = (string) ($settings['mode'] ?? 'sandbox');
        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        if ($clientId === '' || $clientSecret === '') {
            abort(422, 'PayPal is not configured for this store.');
        }

        $tokenResponse = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post($baseUrl.'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $tokenResponse->successful()) {
            abort(422, 'PayPal could not authenticate this store.');
        }

        $returnUrl = route('checkout.show', [
            'order' => $order,
            'token' => data_get($order->metadata, 'checkout_token'),
        ]);

        $orderResponse = Http::withToken((string) $tokenResponse->json('access_token'))
            ->post($baseUrl.'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $order->order_number,
                    'custom_id' => (string) $order->id,
                    'amount' => [
                        'currency_code' => strtoupper($order->currency),
                        'value' => number_format((float) $order->total_amount, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url' => $returnUrl.'?payment=success',
                    'cancel_url' => $returnUrl.'?payment=cancelled',
                ],
            ]);

        if (! $orderResponse->successful()) {
            abort(422, 'PayPal could not start checkout.');
        }

        $approvalUrl = collect($orderResponse->json('links', []))
            ->firstWhere('rel', 'approve')['href'] ?? null;

        if (! is_string($approvalUrl) || $approvalUrl === '') {
            abort(422, 'PayPal did not return an approval URL.');
        }

        return [
            'provider' => 'paypal',
            'checkout_url' => $approvalUrl,
            'provider_session_id' => (string) $orderResponse->json('id'),
        ];
    }
}
