<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CartResource;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Analytics\CommerceAttributionService;
use App\Support\TeamApiAuthorizer;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request, TeamApiAuthorizer $authorizer): CartResource
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'session_key' => ['required', 'string', 'max:255'],
        ]);
        $authorizer->assertPlayerAccess($request, $validated['team_id']);

        $cart = Cart::query()->firstOrCreate(
            [
                'team_id' => $validated['team_id'],
                'session_key' => $validated['session_key'],
                'status' => 'active',
            ],
            [
                'user_id' => $request->user()?->id,
                'currency' => 'USD',
            ],
        );

        return new CartResource($cart->load('items.product'));
    }

    public function addItem(Request $request, TeamApiAuthorizer $authorizer, CommerceAttributionService $attributionService): CartResource
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'session_key' => ['required', 'string', 'max:255'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
            'video_product_tag_id' => ['nullable', 'integer'],
            'starts_at_ms' => ['nullable', 'integer', 'min:0'],
        ]);
        $authorizer->assertPlayerAccess($request, $validated['team_id']);

        $cart = Cart::query()->firstOrCreate(
            [
                'team_id' => $validated['team_id'],
                'session_key' => $validated['session_key'],
                'status' => 'active',
            ],
            [
                'user_id' => $request->user()?->id,
                'currency' => 'USD',
            ],
        );

        $product = Product::query()->findOrFail($validated['product_id']);
        abort_if($product->team_id !== $validated['team_id'], 422, 'Product does not belong to team.');

        $resolvedVariantId = $this->resolveVariantId(
            $product,
            isset($validated['product_variant_id']) ? (int) $validated['product_variant_id'] : null,
        );

        $quantity = (int) ($validated['quantity'] ?? 1);
        $unitPrice = $this->resolveUnitPrice($product, $resolvedVariantId);

        $item = $cart->items()->firstOrNew([
            'product_id' => $product->id,
            'product_variant_id' => $resolvedVariantId,
        ]);

        $item->quantity = ($item->exists ? $item->quantity : 0) + $quantity;
        $item->unit_price = $unitPrice;
        $item->line_total = $item->unit_price * $item->quantity;

        $incomingMetadata = $attributionService->itemMetadataFromInput($validated);

        if ($incomingMetadata !== []) {
            $item->metadata = array_merge((array) ($item->metadata ?? []), $incomingMetadata);
        }

        $item->save();

        $cart->update(['total_amount' => $cart->items()->sum('line_total')]);

        return new CartResource($cart->fresh('items.product'));
    }

    public function removeItem(Request $request, int $itemId, TeamApiAuthorizer $authorizer): CartResource
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'session_key' => ['required', 'string', 'max:255'],
        ]);
        $authorizer->assertPlayerAccess($request, $validated['team_id']);

        $cart = Cart::query()
            ->where('team_id', $validated['team_id'])
            ->where('session_key', $validated['session_key'])
            ->where('status', 'active')
            ->firstOrFail();

        $cart->items()->whereKey($itemId)->delete();
        $cart->update(['total_amount' => $cart->items()->sum('line_total')]);

        return new CartResource($cart->fresh('items.product'));
    }

    protected function resolveVariantId(Product $product, ?int $requestedVariantId): ?int
    {
        if ($requestedVariantId !== null && $requestedVariantId > 0) {
            $variant = ProductVariant::query()->find($requestedVariantId);

            if (
                $variant !== null
                && $variant->product_id === $product->id
                && $variant->team_id === $product->team_id
            ) {
                return $variant->id;
            }
        }

        $fallback = $product->variants()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        return $fallback?->id;
    }

    protected function resolveUnitPrice(Product $product, ?int $variantId): float
    {
        if ($variantId !== null) {
            $variant = ProductVariant::query()->find($variantId);

            if ($variant !== null) {
                return (float) ($variant->sale_price ?? $variant->price ?? $product->sale_price ?? $product->price);
            }
        }

        return (float) ($product->sale_price ?? $product->price);
    }
}
