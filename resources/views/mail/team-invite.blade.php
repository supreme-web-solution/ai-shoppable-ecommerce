<x-mail::message>
# Team invitation

**{{ $inviterName }}** invited you to join **{{ $teamName }}** as a **{{ $role }}**.

Click below to accept the invitation. If you do not have an account yet, you can create one with the same email address.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This invitation expires on {{ $expiresAt->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}.

If you were not expecting this email, you can ignore it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
