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

@php
    $digitalItems = $order->items->filter(function ($item) {
        return data_get($item->metadata, 'product_type') === 'digital'
            && filled(data_get($item->metadata, 'digital_access_url'));
    });
@endphp

@if ($digitalItems->isNotEmpty())
## Your digital products

Access your digital products below:

@foreach ($digitalItems as $item)
@php
    $accessUrl = (string) data_get($item->metadata, 'digital_access_url');
    $accessLabel = data_get($item->metadata, 'digital_file_name')
        ?: (data_get($item->metadata, 'digital_access_type') === 'link' ? 'Open access link' : 'Download');
@endphp
- **{{ $item->title }}** — [{{ $accessLabel }}]({{ $accessUrl }})
@endforeach
@endif

@if ($receiptUrl)
<x-mail::button :url="$receiptUrl">
Download PDF receipt
</x-mail::button>
@endif

If you have questions about this order, reply to this email.

Thanks,<br>
{{ $storeName }}
</x-mail::message>
