<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CartResource;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
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

    public function addItem(Request $request, TeamApiAuthorizer $authorizer): CartResource
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'session_key' => ['required', 'string', 'max:255'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
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

        $variantId = (int) ($validated['product_variant_id'] ?? 0);
        if ($variantId > 0) {
            $variant = ProductVariant::query()->findOrFail($variantId);
            abort_if($variant->product_id !== $product->id, 422, 'Variant does not belong to product.');
            abort_if($variant->team_id !== $validated['team_id'], 422, 'Variant does not belong to team.');
        }

        $quantity = (int) ($validated['quantity'] ?? 1);

        $item = $cart->items()->firstOrNew([
            'product_id' => $product->id,
            'product_variant_id' => $validated['product_variant_id'] ?? null,
        ]);

        $item->quantity = ($item->exists ? $item->quantity : 0) + $quantity;
        $item->unit_price = $product->sale_price ?? $product->price;
        $item->line_total = $item->unit_price * $item->quantity;
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
}
