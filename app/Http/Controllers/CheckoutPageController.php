<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Response;

class CheckoutPageController extends Controller
{
    public function show(Request $request, Order $order, string $token): Response
    {
        abort_unless(hash_equals((string) data_get($order->metadata, 'checkout_token'), $token), 404);
        abort_unless($order->checkout_mode === 'native', 404);

        return inertia('checkout/Show', [
            'order' => $order->load('items', 'team'),
            'token' => $token,
            'paymentStatus' => $request->string('payment')->toString(),
        ]);
    }
}
