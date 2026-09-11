<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Auth\OtpService;
use App\Services\Mail\TransactionalMailer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VerifyOtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly JwtService $jwt,
        private readonly TransactionalMailer $mailer,
    ) {}

    /**
     * The moment a signup actually completes — RegisterController::store()
     * only gets the account to here. Issues the same {token, user, company}
     * shape RegisterController used to return directly, and this is where
     * WelcomeMail now fires (not at raw signup — an unverified account was
     * never really "welcomed" yet).
     */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Those details don\'t match an account.']);
        }

        if ($user->email_verified_at) {
            throw ValidationException::withMessages(['email' => 'This account is already verified — log in instead.']);
        }

        $this->otp->verify($data['email'], $data['otp']);

        $user->update(['email_verified_at' => now()]);
        $user->load('company');

        // The code was correct and the account is verified — a welcome
        // email that won't send is not a reason to withhold the token.
        $this->mailer->attempt($user->email, new WelcomeMail($user));

        return response()->json([
            'token' => $this->jwt->issue($user, 'user')['token'],
            'user' => $user->only('id', 'name', 'email', 'company_id'),
            'company' => $user->company?->only('id', 'name', 'status'),
        ]);
    }

    /**
     * Always the same message regardless of whether the email matches an
     * account, or matches one that's already verified — same
     * account-existence privacy convention as PasswordResetController.
     *
     * One deliberate exception: a mail transport that refuses the send
     * surfaces as a 502 (OtpService::issue() throws), which does reveal
     * that this email had a code to resend. Taken knowingly — the
     * alternative is telling someone a code is "on its way" when nothing
     * was sent and their only route into the account is this endpoint,
     * and a mailer refusing sends is an outage to fix, not a steady state
     * worth designing the privacy guarantee around.
     */
    public function resend(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->whereNull('email_verified_at')->first();

        if ($user) {
            $this->otp->issue($user->email);
        }

        return response()->json(['message' => 'If that email needs verification, a new code is on its way.']);
    }
}
