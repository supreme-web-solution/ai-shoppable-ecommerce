<?php

namespace App\Jobs;

use App\Mail\OrderReceiptMail;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendOrderReceiptJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(
        public int $orderId,
        public ?string $receiptUrl = null,
    ) {
        $this->onQueue('mail');
    }

    public function handle(): void
    {
        $order = Order::query()
            ->with(['items', 'team'])
            ->find($this->orderId);

        if ($order === null || $order->status !== 'paid' || ! $order->customer_email) {
            return;
        }

        Mail::to($order->customer_email)->send(
            new OrderReceiptMail($order, $this->receiptUrl),
        );
    }
}
