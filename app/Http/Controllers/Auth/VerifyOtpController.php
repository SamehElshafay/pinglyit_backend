<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class VerifyOtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly JwtService $jwt,
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

        Mail::to($user->email)->send(new WelcomeMail($user));

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
