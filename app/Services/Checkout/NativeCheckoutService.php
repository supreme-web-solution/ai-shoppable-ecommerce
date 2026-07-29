<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\Order;
use App\Services\Analytics\CommerceAttributionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NativeCheckoutService
{
    /**
     * @param  array<string, mixed>  $checkoutPayload
     */
    public function createPendingOrder(Cart $cart, array $checkoutPayload = [], string $provider = 'native'): Order
    {
        return DB::transaction(function () use ($cart, $checkoutPayload, $provider): Order {
            $cart->loadMissing(['items.product']);

            $subtotal = $cart->items->sum('line_total');
            $tax = (float) ($checkoutPayload['tax_amount'] ?? 0);
            $discount = (float) ($checkoutPayload['discount_amount'] ?? 0);
            $total = max($subtotal + $tax - $discount, 0);
            $fallbackVideoId = isset($checkoutPayload['video_id']) ? (int) $checkoutPayload['video_id'] : null;

            $attributionService = app(CommerceAttributionService::class);

            $order = Order::query()->create([
                'team_id' => $cart->team_id,
                'cart_id' => $cart->id,
                'user_id' => $cart->user_id,
                'order_number' => 'ORD-'.Str::upper(Str::random(10)),
                'status' => 'pending',
                'checkout_mode' => 'native',
                'external_provider' => 'none',
                'currency' => $cart->currency,
                'subtotal_amount' => $subtotal,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'metadata' => $attributionService->mergeOrderMetadata($cart, [
                    'billing' => $checkoutPayload['billing'] ?? null,
                    'shipping' => $checkoutPayload['shipping'] ?? null,
                    'source' => 'native_checkout_service',
                    'payment_provider' => $provider,
                    'checkout_token' => Str::random(40),
                ], $fallbackVideoId, $checkoutPayload),
                'ordered_at' => now(),
            ]);

            foreach ($cart->items as $item) {
                $product = $item->product;
                $digitalSnapshot = $product?->digitalAccessSnapshot() ?? [
                    'product_type' => 'physical',
                    'digital_access_type' => null,
                    'digital_access_url' => null,
                    'digital_file_name' => null,
                ];

                $order->items()->create([
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'title' => $product?->title ?? 'Unknown Product',
                    'sku' => $product?->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'metadata' => array_merge(
                        is_array($item->metadata) ? $item->metadata : [],
                        $digitalSnapshot,
                    ),
                ]);
            }

            $cart->update([
                'checkout_mode' => 'native',
                'external_provider' => 'none',
                'total_amount' => $total,
            ]);

            return $order->fresh('items');
        });
    }
}
