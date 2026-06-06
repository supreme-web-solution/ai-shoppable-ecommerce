<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Checkout\PendingCheckoutOrderService;
use Illuminate\Http\Request;

class CheckoutOrderController extends Controller
{
    public function updateItemQuantity(
        Request $request,
        Order $order,
        OrderItem $item,
        PendingCheckoutOrderService $pendingCheckoutOrderService,
    ) {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $updated = $pendingCheckoutOrderService->updateItemQuantity(
            $order,
            $item,
            (int) $validated['quantity'],
            $validated['token'],
        );

        return response()->json([
            'order' => new OrderResource($updated->load('items')),
        ]);
    }
}
