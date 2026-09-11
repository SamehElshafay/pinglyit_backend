<?php

namespace App\Services\Mail;

use App\Models\PlatformSetting;

/**
 * Which mail provider is actually live, and its credentials — admin-managed
 * from the dashboard (MailConnectionController), same PlatformSetting
 * pattern as every other connection screen, .env values as the fallback
 * for whichever bit isn't set yet in the DB.
 *
 * Two providers are supported side by side: Resend (needs a verified
 * sending domain to reach any inbox but its own account address — see
 * TransactionalMailer's docblock) and plain SMTP (works against Gmail
 * with an App Password today, no domain verification, at the cost of the
 * deliverability a personal Gmail account has — see PasswordResetMail's
 * git history for why Resend was reached for in the first place). Both are
 * kept configured rather than picking one, so switching back is a toggle
 * here, not a redeploy.
 */
class MailProviderService
{
    public function provider(): string
    {
        return PlatformSetting::get('mail_provider') ?: config('mail.default');
    }

    public function isResend(): bool
    {
        return $this->provider() === 'resend';
    }

    public function fromAddress(): ?string
    {
        return PlatformSetting::get('mail_from_address') ?: config('mail.from.address');
    }

    public function fromName(): ?string
    {
        return PlatformSetting::get('mail_from_name') ?: config('mail.from.name');
    }

    public function resendApiKey(): ?string
    {
        return PlatformSetting::get('mail_resend_api_key') ?: config('services.resend.key');
    }

    /**
     * @return array{host: ?string, port: ?string, username: ?string, password: ?string, encryption: string}
     */
    public function smtpConfig(): array
    {
        return [
            'host' => PlatformSetting::get('mail_smtp_host') ?: config('mail.mailers.smtp.host'),
            'port' => PlatformSetting::get('mail_smtp_port') ?: (string) config('mail.mailers.smtp.port'),
            'username' => PlatformSetting::get('mail_smtp_username') ?: config('mail.mailers.smtp.username'),
            'password' => PlatformSetting::get('mail_smtp_password') ?: config('mail.mailers.smtp.password'),
            'encryption' => PlatformSetting::get('mail_smtp_encryption') ?: 'tls',
        ];
    }

    /**
     * Whether the *currently active* provider has what it needs — not
     * both. Switching providers without filling in the new one's fields
     * is a real, common half-done state, so this reflects that.
     */
    public function isConfigured(): bool
    {
        if ($this->isResend()) {
            return filled($this->resendApiKey());
        }

        $smtp = $this->smtpConfig();

        return filled($smtp['host']) && filled($smtp['username']) && filled($smtp['password']);
    }

    /**
     * Points Laravel's mail config at the active provider right before a
     * send. PlatformSetting is a runtime DB value; Laravel's mail config
     * is otherwise fixed at boot from .env, so this is what makes the
     * admin's choice actually take effect on the next `Mail::` call.
     *
     * A no-op until the admin has actually picked a provider from the
     * dashboard — it only ever overrides keys it has an explicit
     * PlatformSetting value for, `mail.default` included. Anything short
     * of that leaves .env's own mail config (or, in a test, whatever the
     * test itself configured) completely alone rather than silently
     * routing back to Resend.
     */
    public function applyRuntimeConfig(): void
    {
        $provider = PlatformSetting::get('mail_provider');

        if (blank($provider)) {
            return;
        }

        $overrides = ['mail.default' => $provider];

        if (filled($from = $this->fromAddress())) {
            $overrides['mail.from.address'] = $from;
        }
        if (filled($fromName = $this->fromName())) {
            $overrides['mail.from.name'] = $fromName;
        }

        if ($provider === 'resend') {
            if (filled($key = PlatformSetting::get('mail_resend_api_key'))) {
                $overrides['services.resend.key'] = $key;
            }
        } elseif ($provider === 'smtp') {
            foreach (['host', 'port', 'username', 'password'] as $field) {
                if (filled($value = PlatformSetting::get("mail_smtp_{$field}"))) {
                    $overrides["mail.mailers.smtp.{$field}"] = $value;
                }
            }

            if (filled($encryption = PlatformSetting::get('mail_smtp_encryption'))) {
                // Symfony Mailer's scheme, not a Laravel "encryption" key
                // (this app's mail.php dropped that key already): 'smtps'
                // forces implicit TLS (port 465); leaving it null lets
                // EsmtpTransport negotiate STARTTLS itself, which is what
                // Gmail on port 587 needs.
                $overrides['mail.mailers.smtp.scheme'] = $encryption === 'ssl' ? 'smtps' : null;
            }
        }

        config($overrides);
    }
}
