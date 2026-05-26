<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NativePaymentWebhookController extends Controller
{
    public function stripe(Request $request)
    {
        $payload = $request->all();
        $eventType = (string) data_get($payload, 'type', '');
        $object = (array) data_get($payload, 'data.object', []);

        if (! in_array($eventType, ['checkout.session.completed', 'payment_intent.succeeded'], true)) {
            return response()->json(['received' => true]);
        }

        $order = $this->resolveStripeOrder($object);

        if (! $order) {
            Log::warning('Stripe webhook could not resolve order', [
                'event_type' => $eventType,
                'object_id' => data_get($object, 'id'),
                'client_reference_id' => data_get($object, 'client_reference_id'),
            ]);

            return response()->json(['received' => true]);
        }

        $this->markOrderPaid($order, 'stripe', (string) data_get($object, 'id', ''));

        return response()->json(['received' => true]);
    }

    public function paypal(Request $request)
    {
        $payload = $request->all();
        $eventType = (string) data_get($payload, 'event_type', '');

        if (! in_array($eventType, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED'], true)) {
            return response()->json(['received' => true]);
        }

        $order = $this->resolvePayPalOrder($payload);

        if (! $order) {
            Log::warning('PayPal webhook could not resolve order', [
                'event_type' => $eventType,
                'resource_id' => data_get($payload, 'resource.id'),
            ]);

            return response()->json(['received' => true]);
        }

        $this->markOrderPaid($order, 'paypal', (string) data_get($payload, 'resource.id', ''));

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function resolveStripeOrder(array $object): ?Order
    {
        $orderId = (int) data_get($object, 'metadata.order_id', 0);

        if ($orderId > 0) {
            return Order::query()->find($orderId);
        }

        $orderNumber = (string) data_get($object, 'client_reference_id', '');

        return $orderNumber !== ''
            ? Order::query()->where('order_number', $orderNumber)->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolvePayPalOrder(array $payload): ?Order
    {
        $orderId = (int) (
            data_get($payload, 'resource.purchase_units.0.custom_id')
            ?: data_get($payload, 'resource.supplementary_data.related_ids.order_id')
            ?: 0
        );

        if ($orderId > 0) {
            return Order::query()->find($orderId);
        }

        $orderNumber = (string) (
            data_get($payload, 'resource.purchase_units.0.reference_id')
            ?: data_get($payload, 'resource.invoice_id')
            ?: ''
        );

        return $orderNumber !== ''
            ? Order::query()->where('order_number', $orderNumber)->first()
            : null;
    }

    protected function markOrderPaid(Order $order, string $provider, string $paymentReference): void
    {
        if ($order->status === 'paid') {
            return;
        }

        $order->update([
            'status' => 'paid',
            'payment_reference' => $paymentReference ?: $order->payment_reference,
            'metadata' => array_merge((array) ($order->metadata ?? []), [
                'payment_provider' => $provider,
                'paid_confirmed_at' => now()->toIso8601String(),
            ]),
        ]);

        $order->cart?->update([
            'status' => 'checked_out',
            'checkout_mode' => 'native',
            'external_provider' => 'none',
        ]);

        Log::info('Native checkout order marked paid from webhook', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'provider' => $provider,
        ]);
    }
}
