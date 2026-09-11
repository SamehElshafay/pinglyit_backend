<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent only by MailConnectionController::test() — the admin dashboard's
 * "send a test email" button on the mail connection screen. Names the
 * provider it went out through so a working send is unambiguous about
 * which configuration to trust.
 */
class MailConnectionTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $provider) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Pingly test email');
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.mail-connection-test',
            text: 'emails.mail-connection-test-text',
            with: ['provider' => $this->provider],
        );
    }
}
