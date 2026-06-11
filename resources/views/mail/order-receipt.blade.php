<x-mail::message>
# Thank you for your purchase!

Hi,

Your payment for **{{ $order->order_number }}** at **{{ $storeName }}** is confirmed.

**Total paid:** {{ $order->total_amount }} {{ $order->currency }}

@if ($paidAt)
**Date:** {{ $paidAt->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}
@endif

## Order items

@foreach ($order->items as $item)
- {{ $item->title }} × {{ $item->quantity }} — {{ $item->line_total }} {{ $order->currency }}
@endforeach

@if ($receiptUrl)
<x-mail::button :url="$receiptUrl">
Download PDF receipt
</x-mail::button>
@endif

If you have questions about this order, reply to this email.

Thanks,<br>
{{ $storeName }}
</x-mail::message>
