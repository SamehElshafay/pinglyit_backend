<?php

namespace App\Services\Mail;

use App\Exceptions\MailDeliveryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Every transactional email goes out through here instead of calling
 * Mail::to() directly, because sending is a live third-party HTTP call
 * (Resend) that genuinely fails in production: while no custom sending
 * domain is verified, Resend rejects every recipient except the account's
 * own signup address, and that rejection arrives as an exception thrown
 * mid-request. Uncaught, it turned ordinary requests into 500s.
 *
 * Also where the admin's choice of provider (MailProviderService — Resend
 * vs. plain SMTP) actually gets applied: every send below points Laravel's
 * mail config at whichever one is active first.
 *
 * Two modes, because the emails here have opposite failure requirements:
 *
 *   deliver() — the email *is* the feature (an OTP code). Failing has to
 *               stop the request loudly, so the caller can roll back and
 *               the user gets told something actionable.
 *   attempt() — the email is a courtesy (welcome mail), or the response
 *               must not vary either way (password reset answers
 *               identically whether or not the account exists — varying
 *               it would leak exactly what that endpoint promises not to).
 *               Failing is logged and swallowed.
 */
class TransactionalMailer
{
    public function __construct(private readonly MailProviderService $provider) {}

    /**
     * @throws MailDeliveryException when the mail can't be handed off
     */
    public function deliver(string $to, Mailable $mailable): void
    {
        $this->provider->applyRuntimeConfig();

        try {
            Mail::to($to)->send($mailable);
        } catch (Throwable $e) {
            $this->logFailure($to, $mailable, $e);

            throw new MailDeliveryException($to, $e);
        }
    }

    /**
     * @return bool whether it actually went out — callers are free to
     *              ignore it, but the log entry is written either way
     */
    public function attempt(string $to, Mailable $mailable): bool
    {
        $this->provider->applyRuntimeConfig();

        try {
            Mail::to($to)->send($mailable);

            return true;
        } catch (Throwable $e) {
            $this->logFailure($to, $mailable, $e);

            return false;
        }
    }

    private function logFailure(string $to, Mailable $mailable, Throwable $e): void
    {
        // The transport's own message is the useful half here — Resend
        // says precisely which recipient or domain it refused.
        Log::error('Transactional email failed to send.', [
            'mailable' => $mailable::class,
            'recipient' => $to,
            'reason' => $e->getMessage(),
        ]);
    }
}
