<?php

namespace App\Mail;

use App\Models\TeamInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TeamInvite $invite) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to join '.$this->invite->team->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.team-invite',
            with: [
                'teamName' => $this->invite->team->name,
                'inviterName' => $this->invite->inviter?->name ?? 'A teammate',
                'role' => $this->invite->role,
                'acceptUrl' => url('/invites/'.$this->invite->token),
                'expiresAt' => $this->invite->expires_at,
            ],
        );
    }
}
