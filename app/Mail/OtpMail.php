<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by RegisterController::store() (a brand-new signup), and again by
 * LoginController::store()/VerifyOtpController::resend() whenever someone
 * tries to log into an account that was never verified — a fresh code
 * every time, never a resend of the same one. See email_otps + docs on
 * VerifyOtpController for the verify/expiry/attempts rules.
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $otp) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your Pingly verification code: {$this->otp}");
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.otp',
            text: 'emails.otp-text',
        );
    }
}
