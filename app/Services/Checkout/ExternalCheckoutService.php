<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\ExternalCheckoutSession;
use App\Models\Team;
use Illuminate\Support\Str;

class ExternalCheckoutService
{
    public function createSession(Cart $cart, Team $team, string $provider): ExternalCheckoutSession
    {
        $provider = in_array($provider, ['shopify', 'woocommerce'], true) ? $provider : 'shopify';
        $providerSessionId = $provider.'_'.Str::uuid()->toString();

        $checkoutUrl = match ($provider) {
            'shopify' => rtrim((string) config('services.shopify.checkout_base', 'https://checkout.shopify.com'), '/').'/'.$providerSessionId,
            default => rtrim((string) config('services.woocommerce.checkout_base', 'https://example.com/checkout'), '/').'/'.$providerSessionId,
        };

        $session = ExternalCheckoutSession::query()->create([
            'team_id' => $team->id,
            'cart_id' => $cart->id,
            'provider' => $provider,
            'provider_session_id' => $providerSessionId,
            'checkout_url' => $checkoutUrl,
            'status' => 'created',
            'payload' => [
                'cart_total' => $cart->total_amount,
                'currency' => $cart->currency,
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        $cart->update([
            'checkout_mode' => 'external',
            'external_provider' => $provider,
        ]);

        return $session;
    }
}
