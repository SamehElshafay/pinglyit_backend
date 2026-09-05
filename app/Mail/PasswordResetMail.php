<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by PasswordResetController::forgot(). Same branded HTML/text pair
 * (with the shared banner — see WelcomeMail/OtpMail) as every other
 * transactional email on the platform.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $resetUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Pingly password');
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.password-reset',
            text: 'emails.password-reset-text',
        );
    }
}
