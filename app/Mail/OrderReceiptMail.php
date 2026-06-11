<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class OrderReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public ?string $receiptUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Receipt for order '.$this->order->order_number,
        );
    }

    public function content(): Content
    {
        $paidAtRaw = data_get($this->order->metadata, 'paid_confirmed_at');
        $paidAt = is_string($paidAtRaw) && $paidAtRaw !== ''
            ? Carbon::parse($paidAtRaw)
            : $this->order->updated_at;

        return new Content(
            markdown: 'mail.order-receipt',
            with: [
                'order' => $this->order,
                'storeName' => $this->order->team?->name ?? 'Store',
                'paidAt' => $paidAt,
                'receiptUrl' => $this->receiptUrl,
            ],
        );
    }
}
