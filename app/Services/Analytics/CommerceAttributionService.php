<?php

namespace App\Services\Analytics;

use App\Models\Cart;
use App\Models\Order;
use Illuminate\Support\Arr;

class CommerceAttributionService
{
    public function __construct(
        protected EventIngestionService $eventIngestionService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, int>
     */
    public function itemMetadataFromInput(array $input): array
    {
        return array_filter([
            'video_id' => isset($input['video_id']) ? (int) $input['video_id'] : null,
            'video_product_tag_id' => isset($input['video_product_tag_id']) ? (int) $input['video_product_tag_id'] : null,
            'starts_at_ms' => isset($input['starts_at_ms']) ? (int) $input['starts_at_ms'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{video_id: ?int, lines: list<array<string, mixed>>, session_key: ?string}
     */
    public function buildFromCart(Cart $cart): array
    {
        $cart->loadMissing('items');

        $lines = [];
        $primaryVideoId = null;
        $primaryWeight = -1.0;

        foreach ($cart->items as $item) {
            $meta = (array) ($item->metadata ?? []);
            $line = [
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'video_id' => isset($meta['video_id']) ? (int) $meta['video_id'] : null,
                'video_product_tag_id' => isset($meta['video_product_tag_id']) ? (int) $meta['video_product_tag_id'] : null,
                'starts_at_ms' => isset($meta['starts_at_ms']) ? (int) $meta['starts_at_ms'] : null,
                'line_total' => (float) $item->line_total,
            ];
            $lines[] = $line;

            if ($line['video_id'] !== null && (float) $item->line_total >= $primaryWeight) {
                $primaryWeight = (float) $item->line_total;
                $primaryVideoId = $line['video_id'];
            }
        }

        return [
            'video_id' => $primaryVideoId,
            'lines' => $lines,
            'session_key' => $cart->session_key,
        ];
    }

    public function resolveVideoId(Cart $cart, ?int $fallbackVideoId = null): ?int
    {
        $fromCart = $this->buildFromCart($cart)['video_id'];

        return $fromCart ?? ($fallbackVideoId > 0 ? $fallbackVideoId : null);
    }

    public function recordCheckoutStarted(Cart $cart, int $teamId, ?int $fallbackVideoId = null): void
    {
        $attribution = $this->buildFromCart($cart);
        $videoId = $attribution['video_id'] ?? ($fallbackVideoId > 0 ? $fallbackVideoId : null);

        $this->eventIngestionService->ingest([
            'team_id' => $teamId,
            'video_id' => $videoId,
            'event_name' => 'checkout_started',
            'source' => 'checkout_api',
            'platform' => 'web_embed',
            'session_key' => $cart->session_key,
            'occurred_at' => now(),
            'payload' => [
                'cart_id' => $cart->id,
                'total_amount' => (float) $cart->total_amount,
                'currency' => $cart->currency,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordCheckoutExternalRedirect(
        Cart $cart,
        int $teamId,
        string $provider,
        ?string $checkoutUrl = null,
        ?int $fallbackVideoId = null,
        array $payload = [],
    ): void {
        $videoId = $this->resolveVideoId($cart, $fallbackVideoId);

        $this->eventIngestionService->ingest([
            'team_id' => $teamId,
            'video_id' => $videoId,
            'event_name' => 'checkout_external_redirect',
            'source' => 'checkout_api',
            'platform' => 'web_embed',
            'session_key' => $cart->session_key,
            'occurred_at' => now(),
            'payload' => array_merge([
                'cart_id' => $cart->id,
                'provider' => $provider,
                'checkout_url' => $checkoutUrl,
                'total_amount' => (float) $cart->total_amount,
                'currency' => $cart->currency,
            ], $payload),
        ]);
    }

    public function recordCheckoutCompleted(Order $order): void
    {
        if ($order->status !== 'paid') {
            return;
        }

        $order->loadMissing('cart');

        $videoId = data_get($order->metadata, 'attribution.video_id');
        $videoId = is_numeric($videoId) ? (int) $videoId : null;
        $sessionKey = (string) (data_get($order->metadata, 'attribution.session_key') ?? $order->cart?->session_key ?? '');

        $this->eventIngestionService->ingest([
            'team_id' => $order->team_id,
            'video_id' => $videoId,
            'event_name' => 'checkout_completed',
            'source' => 'checkout_api',
            'platform' => 'web_embed',
            'session_key' => $sessionKey !== '' ? $sessionKey : null,
            'occurred_at' => now(),
            'payload' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total_amount' => (float) $order->total_amount,
                'currency' => $order->currency,
                'checkout_mode' => $order->checkout_mode,
            ],
        ], revenueAmount: (float) $order->total_amount);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function mergeOrderMetadata(Cart $cart, array $metadata = [], ?int $fallbackVideoId = null): array
    {
        $attribution = $this->buildFromCart($cart);

        if ($attribution['video_id'] === null && $fallbackVideoId > 0) {
            $attribution['video_id'] = $fallbackVideoId;
        }

        return array_merge($metadata, [
            'attribution' => Arr::only($attribution, ['video_id', 'lines', 'session_key']),
        ]);
    }
}
