<?php

namespace App\Http\Controllers\Api\V1\Player;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Checkout\NativePaymentSessionService;
use Illuminate\Http\Request;

class NativePaymentController extends Controller
{
    public function start(Request $request, Order $order, NativePaymentSessionService $paymentSessionService)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        abort_unless(hash_equals((string) data_get($order->metadata, 'checkout_token'), $validated['token']), 404);
        abort_if($order->status !== 'pending', 422, 'This order is not awaiting payment.');

        $session = $paymentSessionService->createSession($order->load('team'));

        $order->update([
            'payment_reference' => $session['provider_session_id'],
            'metadata' => array_merge((array) ($order->metadata ?? []), [
                'payment_session' => [
                    'provider' => $session['provider'],
                    'provider_session_id' => $session['provider_session_id'],
                ],
            ]),
        ]);

        return response()->json($session);
    }
}
