<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Cart;
use App\Models\Team;
use App\Services\Checkout\ExternalCheckoutService;
use App\Services\Checkout\NativeCheckoutService;
use App\Services\Checkout\TeamCheckoutResolver;
use App\Support\TeamApiAuthorizer;

class CheckoutController extends Controller
{
    public function checkout(
        CheckoutRequest $request,
        NativeCheckoutService $nativeCheckoutService,
        ExternalCheckoutService $externalCheckoutService,
        TeamCheckoutResolver $checkoutResolver,
        TeamApiAuthorizer $authorizer,
    ) {
        $validated = $request->validated();
        $authorizer->assertPlayerAccess($request, $validated['team_id']);

        $team = Team::query()->findOrFail($validated['team_id']);
        $cart = Cart::query()
            ->with(['items.product', 'items.variant'])
            ->findOrFail($validated['cart_id']);

        abort_if($cart->team_id !== $team->id, 422, 'Cart does not belong to team.');
        abort_if($cart->status !== 'active', 422, 'Cart is not active.');

        $resolved = $checkoutResolver->resolve(
            $team,
            $validated['checkout_mode'],
            $validated['external_provider'] ?? null,
        );

        if ($resolved['mode'] === 'native') {
            $nativeProvider = $checkoutResolver->activeNativeProvider($team);

            if ($nativeProvider === null) {
                return response()->json([
                    'message' => 'Native checkout is not configured. Connect Stripe or PayPal in Settings > Integrations, or enable Shopify/WooCommerce for external checkout.',
                    'mode' => 'native_unavailable',
                    'settings_url' => '/settings/integrations',
                ], 422);
            }

            $order = $nativeCheckoutService->createPendingOrder($cart, $validated, $nativeProvider);
            $checkoutUrl = route('checkout.show', [
                'order' => $order,
                'token' => data_get($order->metadata, 'checkout_token'),
            ]);

            return response()->json([
                'mode' => 'native',
                'provider' => $nativeProvider,
                'checkout_url' => $checkoutUrl,
                'order' => new OrderResource($order->load('items')),
            ], 201);
        }

        $shopifyLines = [];
        $wooLines = [];

        if ($resolved['provider'] === 'shopify') {
            $shopifyLines = $externalCheckoutService->shopifyCartLines($cart);

            if ($shopifyLines === []) {
                return response()->json([
                    'message' => 'Your cart has no Shopify products. Only items synced from Shopify can be checked out on your store. Use Stripe/PayPal for local products, or add Shopify-synced products to the cart.',
                    'mode' => 'external_unavailable',
                    'settings_url' => '/settings/integrations',
                ], 422);
            }
        }

        if ($resolved['provider'] === 'woocommerce') {
            $wooLines = $externalCheckoutService->wooCartLines($cart);

            if ($wooLines === []) {
                return response()->json([
                    'message' => 'Your cart has no WooCommerce products. Only items synced from WooCommerce can be checked out on your store. Use Stripe/PayPal for local products, or add WooCommerce-synced products to the cart.',
                    'mode' => 'external_unavailable',
                    'settings_url' => '/settings/integrations',
                ], 422);
            }
        }

        $session = $externalCheckoutService->createSession($cart, $team, $resolved['provider']);

        return response()->json([
            'mode' => 'external',
            'provider' => $resolved['provider'],
            'checkout_url' => $session->checkout_url,
            'session_id' => $session->id,
            'shopify_lines' => $resolved['provider'] === 'shopify' ? count($shopifyLines) : null,
            'woo_lines' => $resolved['provider'] === 'woocommerce' ? count($wooLines) : null,
        ], 201);
    }
}
