<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Checkout\NativePaymentConfirmationService;
use Illuminate\Http\Request;
use Inertia\Response;
use Throwable;

class CheckoutPageController extends Controller
{
    public function show(
        Request $request,
        Order $order,
        string $token,
        NativePaymentConfirmationService $confirmationService,
    ): Response {
        abort_unless(hash_equals((string) data_get($order->metadata, 'checkout_token'), $token), 404);
        abort_unless($order->checkout_mode === 'native', 404);

        $order->load('items', 'team');
        $confirmationError = null;

        if ($request->string('payment')->toString() === 'success' && $order->status === 'pending') {
            try {
                $paypalOrderId = null;

                if ((string) data_get($order->metadata, 'payment_provider') === 'paypal') {
                    $paypalOrderId = $request->string('token')->toString() ?: null;
                }

                $order = $confirmationService->confirm(
                    $order,
                    stripeSessionId: $request->string('session_id')->toString() ?: null,
                    paypalOrderId: $paypalOrderId,
                )->load('items', 'team');
            } catch (Throwable $exception) {
                $confirmationError = $exception->getMessage();
            }
        }

        return inertia('checkout/Show', [
            'order' => $order,
            'token' => $token,
            'paymentStatus' => $request->string('payment')->toString(),
            'confirmationError' => $confirmationError,
        ]);
    }
}
