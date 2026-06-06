<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Checkout\CheckoutReturnUrlResolver;
use App\Services\Checkout\NativePaymentConfirmationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Inertia\Response as InertiaResponse;
use Throwable;

class CheckoutPageController extends Controller
{
    public function show(
        Request $request,
        Order $order,
        string $token,
        NativePaymentConfirmationService $confirmationService,
        CheckoutReturnUrlResolver $returnUrlResolver,
    ): InertiaResponse {
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
            'returnUrl' => $returnUrlResolver->resolve($order),
            'receiptUrl' => route('checkout.receipt', [
                'order' => $order,
                'token' => $token,
            ]),
        ]);
    }

    public function receipt(Request $request, Order $order, string $token): Response
    {
        abort_unless(hash_equals((string) data_get($order->metadata, 'checkout_token'), $token), 404);
        abort_unless($order->checkout_mode === 'native', 404);
        abort_unless($order->status === 'paid', 404);

        $order->load('items', 'team');

        $paidAtRaw = data_get($order->metadata, 'paid_confirmed_at');
        $paidAt = is_string($paidAtRaw) && $paidAtRaw !== ''
            ? Carbon::parse($paidAtRaw)
            : $order->updated_at;

        $html = view('checkout.receipt', [
            'order' => $order,
            'paidAt' => $paidAt,
        ])->render();

        $filename = 'receipt-'.$order->order_number.'.pdf';

        return Pdf::loadHTML($html)
            ->setPaper('a4')
            ->download($filename);
    }
}
