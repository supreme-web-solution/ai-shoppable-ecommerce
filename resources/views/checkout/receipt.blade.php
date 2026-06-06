<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt · {{ $order->order_number }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif; color: #0f172a; margin: 0; padding: 32px; background: #f8fafc; }
        .sheet { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        .muted { color: #64748b; font-size: 14px; }
        .badge { display: inline-block; margin-top: 12px; padding: 4px 10px; border-radius: 999px; background: #ecfdf5; color: #047857; font-size: 12px; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 14px; }
        th, td { text-align: left; padding: 12px 0; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        th { color: #64748b; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .total-row td { border-bottom: none; font-size: 16px; font-weight: 700; padding-top: 16px; }
        .footer { margin-top: 28px; font-size: 12px; color: #94a3b8; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { border: none; border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <h1>Payment receipt</h1>
        <p class="muted">{{ $order->team?->name ?? 'Store' }}</p>
        <span class="badge">Paid</span>

        <table>
            <tbody>
                <tr>
                    <th scope="row">Order</th>
                    <td>{{ $order->order_number }}</td>
                </tr>
                <tr>
                    <th scope="row">Date</th>
                    <td>{{ optional($paidAt)->timezone(config('app.timezone'))->format('M j, Y g:i A T') ?? $order->updated_at->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}</td>
                </tr>
                <tr>
                    <th scope="row">Payment</th>
                    <td>{{ ucfirst((string) data_get($order->metadata, 'payment_provider', 'card')) }}</td>
                </tr>
            </tbody>
        </table>

        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->title }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td style="text-align:right;">{{ $item->line_total }} {{ $order->currency }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="2">Total</td>
                    <td style="text-align:right;">{{ $order->total_amount }} {{ $order->currency }}</td>
                </tr>
            </tbody>
        </table>

        <p class="footer">Thank you for your purchase. Keep this receipt for your records.</p>
    </div>
</body>
</html>
