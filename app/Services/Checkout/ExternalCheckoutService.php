<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ExternalCheckoutSession;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExternalCheckoutService
{
    public function createSession(Cart $cart, Team $team, string $provider): ExternalCheckoutSession
    {
        $provider = in_array($provider, ['shopify', 'woocommerce'], true) ? $provider : 'shopify';
        $providerSessionId = $provider.'_'.Str::uuid()->toString();

        $checkoutUrl = match ($provider) {
            'shopify' => $this->shopifyCheckoutUrl($cart, $team),
            default => $this->wooCheckoutUrl($cart, $team),
        };

        $shopifyLines = $provider === 'shopify'
            ? $this->buildShopifyCartLines($cart)
            : [];
        $wooLines = $provider === 'woocommerce'
            ? $this->buildWooCartLines($cart)
            : [];

        Log::info('External checkout session creating', [
            'team_id' => $team->id,
            'cart_id' => $cart->id,
            'provider' => $provider,
            'checkout_url' => $checkoutUrl,
            'cart_total' => $cart->total_amount,
            'currency' => $cart->currency,
            'item_count' => $cart->items?->count() ?? 0,
            'shopify_lines' => $shopifyLines,
            'woo_lines' => $wooLines,
        ]);

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
                'shopify_lines' => $shopifyLines,
                'woo_lines' => $wooLines,
            ],
            'expires_at' => now()->addMinutes(30),
        ]);

        $cart->update([
            'checkout_mode' => 'external',
            'external_provider' => $provider,
        ]);

        Log::info('External checkout session created', [
            'session_id' => $session->id,
            'team_id' => $team->id,
            'provider' => $provider,
            'checkout_url' => $session->checkout_url,
        ]);

        return $session;
    }

    /**
     * @return array<int, array{variant_id: string, quantity: int, product_id: int, product_title: string}>
     */
    public function shopifyCartLines(Cart $cart): array
    {
        return $this->buildShopifyCartLines($cart);
    }

    /**
     * @return array<int, array{product_id: string, quantity: int, product_title: string}>
     */
    public function wooCartLines(Cart $cart): array
    {
        return $this->buildWooCartLines($cart);
    }

    protected function shopifyCheckoutUrl(Cart $cart, Team $team): string
    {
        $shopUrl = trim((string) data_get($team->settings, 'integrations.shopify.shop_url', ''));
        $host = $this->normalizeShopHost($shopUrl);
        $lines = $this->buildShopifyCartLines($cart);

        if ($lines === []) {
            Log::warning('Shopify checkout URL has no mappable line items; falling back to empty cart page', [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
            ]);

            return "https://{$host}/cart";
        }

        $path = collect($lines)
            ->map(fn (array $line): string => $line['variant_id'].':'.$line['quantity'])
            ->implode(',');

        return "https://{$host}/cart/{$path}";
    }

    /**
     * @return array<int, array{variant_id: string, quantity: int, product_id: int, product_title: string}>
     */
    protected function buildShopifyCartLines(Cart $cart): array
    {
        $cart->loadMissing(['items.product.variants', 'items.variant']);

        /** @var Collection<string, array{variant_id: string, quantity: int, product_id: int, product_title: string}> $merged */
        $merged = collect();

        foreach ($cart->items as $item) {
            $shopifyVariantId = $this->resolveShopifyVariantId($item);

            if ($shopifyVariantId === null) {
                Log::warning('Shopify checkout skipped cart item without Shopify variant mapping', [
                    'cart_id' => $cart->id,
                    'cart_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_source' => $item->product?->source,
                    'product_title' => $item->product?->title,
                ]);

                continue;
            }

            $existing = $merged->get($shopifyVariantId);

            if ($existing !== null) {
                $existing['quantity'] += (int) $item->quantity;
                $merged->put($shopifyVariantId, $existing);

                continue;
            }

            $merged->put($shopifyVariantId, [
                'variant_id' => $shopifyVariantId,
                'quantity' => (int) $item->quantity,
                'product_id' => (int) $item->product_id,
                'product_title' => (string) ($item->product?->title ?? ''),
            ]);
        }

        return $merged->values()->all();
    }

    protected function resolveShopifyVariantId(CartItem $item): ?string
    {
        $product = $item->product;

        if ($product === null || $product->source !== 'shopify') {
            return null;
        }

        $selectedVariantId = trim((string) ($item->variant?->external_id ?? ''));

        if ($selectedVariantId !== '') {
            return $selectedVariantId;
        }

        $fallbackVariant = $product->variants
            ->first(fn ($variant): bool => trim((string) ($variant->external_id ?? '')) !== '')
            ?? $product->variants()->whereNotNull('external_id')->where('external_id', '!=', '')->orderByDesc('is_default')->first();

        $externalId = trim((string) ($fallbackVariant?->external_id ?? ''));

        return $externalId !== '' ? $externalId : null;
    }

    protected function normalizeShopHost(string $shopUrl): string
    {
        if ($shopUrl === '') {
            return 'example.myshopify.com';
        }

        $host = strtolower(preg_replace('#^https?://#', '', $shopUrl) ?? $shopUrl);
        $host = rtrim($host, '/');

        if (! str_contains($host, '.')) {
            $host = "{$host}.myshopify.com";
        }

        return $host;
    }

    protected function wooCheckoutUrl(Cart $cart, Team $team): string
    {
        $siteUrl = $this->normalizeWooSiteUrl((string) data_get($team->settings, 'integrations.woocommerce.site_url', ''));
        $lines = $this->buildWooCartLines($cart);

        if ($lines === []) {
            Log::warning('WooCommerce checkout URL has no mappable line items; falling back to empty cart page', [
                'team_id' => $team->id,
                'cart_id' => $cart->id,
            ]);

            return "{$siteUrl}/cart";
        }

        if (count($lines) === 1) {
            $line = $lines[0];

            return sprintf(
                '%s/checkout/?add-to-cart=%s&quantity=%d',
                $siteUrl,
                rawurlencode($line['product_id']),
                max(1, (int) $line['quantity']),
            );
        }

        $ids = collect($lines)
            ->map(fn (array $line): string => (string) $line['product_id'])
            ->implode(',');

        $query = ['add-to-cart' => $ids];
        foreach ($lines as $line) {
            $query["quantity[{$line['product_id']}]"] = max(1, (int) $line['quantity']);
        }

        return "{$siteUrl}/cart/?".http_build_query($query);
    }

    /**
     * @return array<int, array{product_id: string, quantity: int, product_title: string}>
     */
    protected function buildWooCartLines(Cart $cart): array
    {
        $cart->loadMissing(['items.product']);

        /** @var Collection<string, array{product_id: string, quantity: int, product_title: string}> $merged */
        $merged = collect();

        foreach ($cart->items as $item) {
            $wooProductId = $this->resolveWooProductId($item);

            if ($wooProductId === null) {
                Log::warning('WooCommerce checkout skipped cart item without Woo product mapping', [
                    'cart_id' => $cart->id,
                    'cart_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_source' => $item->product?->source,
                    'product_title' => $item->product?->title,
                ]);

                continue;
            }

            $existing = $merged->get($wooProductId);

            if ($existing !== null) {
                $existing['quantity'] += (int) $item->quantity;
                $merged->put($wooProductId, $existing);

                continue;
            }

            $merged->put($wooProductId, [
                'product_id' => $wooProductId,
                'quantity' => (int) $item->quantity,
                'product_title' => (string) ($item->product?->title ?? ''),
            ]);
        }

        return $merged->values()->all();
    }

    protected function resolveWooProductId(CartItem $item): ?string
    {
        $product = $item->product;

        if ($product === null || $product->source !== 'woocommerce') {
            return null;
        }

        $externalId = trim((string) ($product->external_id ?? ''));

        return $externalId !== '' ? $externalId : null;
    }

    protected function normalizeWooSiteUrl(string $siteUrl): string
    {
        $siteUrl = trim($siteUrl);
        if ($siteUrl === '') {
            $siteUrl = (string) config('services.woocommerce.checkout_base', 'https://example.com');
        }

        if (! str_starts_with($siteUrl, 'http://') && ! str_starts_with($siteUrl, 'https://')) {
            $siteUrl = 'https://'.$siteUrl;
        }

        return rtrim($siteUrl, '/');
    }
}
