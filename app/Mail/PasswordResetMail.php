<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by PasswordResetController::forgot(). Plain text only — no HTML
 * template exists yet, and this is the one transactional email the
 * platform sends today. MAIL_MAILER=log until real SMTP creds exist, same
 * fail-clean-when-unconfigured pattern as every other integration here.
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
        return new Content(text: 'emails.password-reset-text');
    }
}
