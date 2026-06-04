<x-mail::message>
# {{ $registrationTitle }}

Hi **{{ $attendeeName }}**,

@if ($startsAt)
**{{ $webinarTitle }}** with **{{ $hostName }}** is scheduled for {{ $startsAt->timezone(config('app.timezone'))->format('l, M j, Y \a\t g:i A T') }}.
@else
You are registered for **{{ $webinarTitle }}** with **{{ $hostName }}**.
@endif

Use the button below to open the registration page or join the webinar room directly.

<x-mail::button :url="$roomUrl">
Join webinar room
</x-mail::button>

<x-mail::button :url="$registerUrl" color="success">
View registration page
</x-mail::button>

If you did not register for this webinar, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
