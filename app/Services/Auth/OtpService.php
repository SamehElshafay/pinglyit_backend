<?php

namespace App\Services\Auth;

use App\Exceptions\MailDeliveryException;
use App\Mail\OtpMail;
use App\Services\Mail\TransactionalMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Email verification for user_website signups — a 6-digit code, mirroring
 * password_reset_tokens' one-row-per-email shape (see the email_otps
 * migration) but with `attempts`: a 6-digit code is far more guessable
 * than a 64-char reset token, so wrong tries are capped here rather than
 * left open-ended.
 *
 * Google sign-in never goes through this — Google's own `email_verified`
 * claim (already checked in GoogleAuthService) is proof enough on its own.
 */
class OtpService
{
    private const EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TransactionalMailer $mailer) {}

    /**
     * (Re)issues a code for this email — used by both a fresh signup and a
     * resend, always overwriting whatever code existed before rather than
     * ever re-sending an old one.
     *
     * The one email on the platform that must not fail quietly: a code
     * nobody receives leaves an account that can't be verified, can't be
     * logged into, and can't be signed up for again. Callers are expected
     * to let the failure abort whatever they were doing.
     *
     * @throws MailDeliveryException when the code can't be sent
     */
    public function issue(string $email): void
    {
        $otp = (string) random_int(100000, 999999);

        DB::table('email_otps')->updateOrInsert(
            ['email' => $email],
            ['otp' => Hash::make($otp), 'attempts' => 0, 'created_at' => now()],
        );

        $this->mailer->deliver($email, new OtpMail($otp));
    }

    /**
     * @throws ValidationException on a missing/expired/wrong/exhausted code
     */
    public function verify(string $email, string $otp): void
    {
        $record = DB::table('email_otps')->where('email', $email)->first();

        if (! $record) {
            throw ValidationException::withMessages(['otp' => 'Invalid or expired code — request a new one.']);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::EXPIRY_MINUTES)->isPast()) {
            DB::table('email_otps')->where('email', $email)->delete();

            throw ValidationException::withMessages(['otp' => 'This code has expired — request a new one.']);
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            DB::table('email_otps')->where('email', $email)->delete();

            throw ValidationException::withMessages(['otp' => 'Too many attempts — request a new code.']);
        }

        if (! Hash::check($otp, $record->otp)) {
            DB::table('email_otps')->where('email', $email)->increment('attempts');

            throw ValidationException::withMessages(['otp' => 'That code is incorrect.']);
        }

        DB::table('email_otps')->where('email', $email)->delete();
    }
}
