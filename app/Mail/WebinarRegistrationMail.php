<?php

namespace App\Mail;

use App\Models\LiveShow;
use App\Models\LiveShowRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WebinarRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LiveShow $liveShow,
        public LiveShowRegistration $registration,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reminder: '.$this->liveShow->title,
        );
    }

    public function content(): Content
    {
        $settings = is_array($this->liveShow->settings) ? $this->liveShow->settings : [];
        $hostName = trim((string) ($settings['host_name'] ?? '')) ?: 'Your host';

        return new Content(
            markdown: 'mail.webinar-registration',
            with: [
                'webinarTitle' => $this->liveShow->title,
                'hostName' => $hostName,
                'startsAt' => $this->liveShow->starts_at,
                'registrationTitle' => trim((string) ($settings['registration_title'] ?? '')) ?: 'Join Webinar',
                'attendeeName' => $this->registration->full_name,
                'registerUrl' => url('/webinars/'.$this->liveShow->id.'/register'),
                'roomUrl' => url('/webinars/'.$this->liveShow->id.'/room?registration='.$this->registration->id),
            ],
        );
    }
}
