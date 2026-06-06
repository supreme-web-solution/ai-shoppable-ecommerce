<?php

namespace App\Services\Checkout;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PendingCheckoutOrderService
{
    public function updateItemQuantity(Order $order, OrderItem $item, int $quantity, string $token): Order
    {
        abort_unless(hash_equals((string) data_get($order->metadata, 'checkout_token'), $token), 404);
        abort_unless($order->checkout_mode === 'native', 404);
        abort_if($order->status !== 'pending', 422, 'This order is not awaiting payment.');
        abort_if($item->order_id !== $order->id, 404);

        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be at least 1.',
            ]);
        }

        return DB::transaction(function () use ($order, $item, $quantity): Order {
            $item->update([
                'quantity' => $quantity,
                'line_total' => (float) $item->unit_price * $quantity,
            ]);

            $this->syncCartItem($order, $item, $quantity);
            $this->recalculateOrderTotals($order);
            $this->clearPaymentSession($order);

            return $order->fresh('items');
        });
    }

    protected function syncCartItem(Order $order, OrderItem $item, int $quantity): void
    {
        if ($order->cart_id === null) {
            return;
        }

        $cart = Cart::query()
            ->whereKey($order->cart_id)
            ->where('status', 'active')
            ->first();

        if ($cart === null) {
            return;
        }

        $cartItemQuery = CartItem::query()->where('cart_id', $cart->id);

        if ($item->product_variant_id !== null) {
            $cartItemQuery->where('product_variant_id', $item->product_variant_id);
        } else {
            $cartItemQuery->where('product_id', $item->product_id)
                ->whereNull('product_variant_id');
        }

        $cartItem = $cartItemQuery->first();

        if ($cartItem === null) {
            return;
        }

        $cartItem->update([
            'quantity' => $quantity,
            'line_total' => (float) $cartItem->unit_price * $quantity,
        ]);

        $cart->update(['total_amount' => $cart->items()->sum('line_total')]);
    }

    protected function recalculateOrderTotals(Order $order): void
    {
        $order->loadMissing('items');

        $subtotal = $order->items->sum('line_total');
        $tax = (float) $order->tax_amount;
        $discount = (float) $order->discount_amount;
        $total = max($subtotal + $tax - $discount, 0);

        $order->update([
            'subtotal_amount' => $subtotal,
            'total_amount' => $total,
        ]);
    }

    protected function clearPaymentSession(Order $order): void
    {
        $metadata = (array) ($order->metadata ?? []);
        unset($metadata['payment_session']);

        $order->update([
            'payment_reference' => null,
            'metadata' => $metadata,
        ]);
    }
}
