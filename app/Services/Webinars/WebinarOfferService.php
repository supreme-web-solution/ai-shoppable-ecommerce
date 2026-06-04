<?php

namespace App\Services\Webinars;

use App\Models\LiveShow;
use App\Models\Product;
use App\Models\Team;
use App\Services\Checkout\TeamCheckoutResolver;

class WebinarOfferService
{
    public const APPEARANCES = ['pin', 'in_chat', 'popup'];

    public function __construct(
        protected TeamCheckoutResolver $checkoutResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $offers
     */
    public function syncOffers(LiveShow $liveShow, array $offers): void
    {
        $syncData = collect($offers)
            ->values()
            ->mapWithKeys(function (array $offer, int $index): array {
                $productId = (int) ($offer['product_id'] ?? 0);
                if ($productId < 1) {
                    return [];
                }

                $appearance = strtolower(trim((string) ($offer['appearance'] ?? 'popup')));
                if (! in_array($appearance, self::APPEARANCES, true)) {
                    $appearance = 'popup';
                }

                $ctaUrl = trim((string) ($offer['cta_url'] ?? ''));

                return [
                    $productId => [
                        'starts_at_ms' => max(0, (int) ($offer['starts_at_ms'] ?? 0)),
                        'ends_at_ms' => isset($offer['ends_at_ms']) && $offer['ends_at_ms'] !== ''
                            ? max(0, (int) $offer['ends_at_ms'])
                            : null,
                        'pin_order' => (int) ($offer['pin_order'] ?? $index),
                        'flash_discount' => $offer['flash_discount'] ?? null,
                        'appearance' => $appearance,
                        'cta_url' => $ctaUrl !== '' ? $ctaUrl : null,
                    ],
                ];
            })
            ->all();

        $liveShow->featuredProducts()->sync($syncData);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatOffersForLiveShow(LiveShow $liveShow): array
    {
        $liveShow->loadMissing(['featuredProducts', 'team']);

        return $liveShow->featuredProducts
            ->sortBy(fn (Product $product): int => (int) ($product->pivot?->pin_order ?? 0))
            ->values()
            ->map(fn (Product $product): array => $this->formatOffer($liveShow, $product))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatOffer(LiveShow $liveShow, Product $product): array
    {
        $pivot = $product->pivot;
        $ctaOverride = trim((string) ($pivot?->cta_url ?? ''));
        $defaultCheckoutUrl = $this->defaultCheckoutUrl($liveShow->team, $product);

        return [
            'id' => $product->id,
            'title' => $product->title,
            'image_url' => $product->image_url,
            'price' => $product->price,
            'sale_price' => $product->sale_price,
            'currency' => $product->currency,
            'starts_at_ms' => (int) ($pivot?->starts_at_ms ?? 0),
            'ends_at_ms' => $pivot?->ends_at_ms !== null ? (int) $pivot->ends_at_ms : null,
            'appearance' => $this->normalizeAppearance((string) ($pivot?->appearance ?? 'popup')),
            'pin_order' => (int) ($pivot?->pin_order ?? 0),
            'cta_url' => $ctaOverride !== '' ? $ctaOverride : null,
            'default_checkout_url' => $defaultCheckoutUrl,
            'checkout_url' => $ctaOverride !== '' ? $ctaOverride : $defaultCheckoutUrl,
        ];
    }

    public function defaultCheckoutUrl(?Team $team, Product $product): string
    {
        if ($team === null) {
            return '';
        }

        if ($product->source === 'shopify') {
            $shopUrl = rtrim(trim((string) data_get($team->settings, 'integrations.shopify.shop_url', '')), '/');
            $handle = trim((string) data_get($product->metadata, 'handle', $product->slug));

            if ($shopUrl !== '' && $handle !== '') {
                return "{$shopUrl}/products/{$handle}";
            }
        }

        if ($product->source === 'woocommerce') {
            $storeUrl = rtrim(trim((string) data_get($team->settings, 'integrations.woocommerce.store_url', '')), '/');
            $permalink = trim((string) data_get($product->metadata, 'permalink', ''));

            if ($permalink !== '') {
                return $permalink;
            }

            if ($storeUrl !== '' && $product->slug !== '') {
                return "{$storeUrl}/product/{$product->slug}/";
            }
        }

        $resolved = $this->checkoutResolver->resolve($team, 'hybrid', null);

        if ($resolved['mode'] === 'external' && $resolved['provider'] !== null) {
            return 'External checkout ('.$resolved['provider'].') — opens when attendees click Shop';
        }

        if ($this->checkoutResolver->isNativeReady($team)) {
            $provider = $this->checkoutResolver->activeNativeProvider($team) ?? 'native';

            return 'Platform checkout ('.$provider.') — opens when attendees click Shop';
        }

        return 'Configure Stripe, PayPal, Shopify, or WooCommerce in Settings → Integrations';
    }

    public function normalizeAppearance(string $appearance): string
    {
        $appearance = strtolower(trim($appearance));

        return in_array($appearance, self::APPEARANCES, true) ? $appearance : 'popup';
    }
}
