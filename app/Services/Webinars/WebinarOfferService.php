<?php

namespace App\Services\Webinars;

use App\Models\LiveShow;
use App\Models\LiveShowMessage;
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

    /**
     * @return array<string, mixed>
     */
    public function formatPlayerMessage(LiveShow $liveShow, LiveShowMessage $message): array
    {
        $payload = [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'sender_name' => $message->sender_name,
            'live_show_registration_id' => $message->live_show_registration_id,
            'message' => $message->message,
            'is_pinned' => (bool) $message->is_pinned,
            'created_at' => $message->created_at,
        ];

        $meta = is_array($message->meta) ? $message->meta : [];
        $productId = (int) data_get($meta, 'offer_product_id', 0);

        if ($productId < 1) {
            return $payload;
        }

        $liveShow->loadMissing(['featuredProducts', 'team']);
        $product = $liveShow->featuredProducts->firstWhere('id', $productId);

        if ($product !== null) {
            $payload['offer'] = $this->formatOffer($liveShow, $product);
        }

        return $payload;
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

    public function isLiveEpochTimestamp(int $milliseconds): bool
    {
        return $milliseconds > 1_000_000_000_000;
    }

    /**
     * @return array<string, mixed>
     */
    public function pushLiveOffer(LiveShow $liveShow, Product $product, ?string $hostName = null): array
    {
        abort_unless(
            data_get($liveShow->settings, 'source_type') === 'daily',
            422,
            'Live offer push is only available for browser go-live casts.',
        );

        $nowMs = (int) round(microtime(true) * 1000);
        $existing = $liveShow->featuredProducts()->whereKey($product->id)->first();
        $maxOrder = (int) $liveShow->featuredProducts()->max('live_show_products.pin_order');

        $liveShow->featuredProducts()->syncWithoutDetaching([
            $product->id => [
                'starts_at_ms' => $nowMs,
                'ends_at_ms' => null,
                'appearance' => 'in_chat',
                'pin_order' => $existing?->pivot?->pin_order ?? ($maxOrder + 1),
                'cta_url' => $existing?->pivot?->cta_url,
            ],
        ]);

        $liveShow->loadMissing(['featuredProducts', 'team']);
        $freshProduct = $liveShow->featuredProducts->firstWhere('id', $product->id);
        abort_if($freshProduct === null, 422, 'Could not attach this product to the live cast.');

        $hostLabel = trim((string) ($hostName ?? data_get($liveShow->settings, 'host_name', 'Host')));
        $hostLabel = $hostLabel !== '' ? $hostLabel : 'Host';

        \App\Models\LiveShowMessage::query()->create([
            'live_show_id' => $liveShow->id,
            'sender_type' => 'host',
            'sender_name' => $hostLabel,
            'message' => 'Featured: '.$product->title,
            'meta' => [
                'offer_product_id' => $product->id,
            ],
        ]);

        return $this->formatOffer($liveShow, $freshProduct);
    }

    public function unpublishLiveOffer(LiveShow $liveShow, Product $product): void
    {
        abort_unless(
            data_get($liveShow->settings, 'source_type') === 'daily',
            422,
            'Live offer unpublish is only available for browser go-live casts.',
        );

        $pivot = $liveShow->featuredProducts()->whereKey($product->id)->first()?->pivot;
        abort_if($pivot === null, 404, 'This product is not assigned to the live cast.');

        $nowMs = (int) round(microtime(true) * 1000);
        $startsAtMs = (int) ($pivot->starts_at_ms ?? 0);

        if (! $this->isLiveEpochTimestamp($startsAtMs)) {
            $liveShow->featuredProducts()->detach($product->id);

            return;
        }

        $liveShow->featuredProducts()->updateExistingPivot($product->id, [
            'ends_at_ms' => $nowMs,
        ]);
    }
}
