<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once, right after a brand-new account is created — password signup
 * (RegisterController::store()) or a first-time Google sign-in
 * (GoogleAuthService::createFromGoogle()). Never sent on a login, only on
 * account creation. MAIL_MAILER=log until real SMTP creds exist, same
 * fail-clean-when-unconfigured pattern as PasswordResetMail.
 */
class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Welcome to Pingly, {$this->user->name}");
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.welcome',
            text: 'emails.welcome-text',
            with: ['dashboardUrl' => rtrim(config('pingly.frontend_url'), '/')],
        );
    }
}
