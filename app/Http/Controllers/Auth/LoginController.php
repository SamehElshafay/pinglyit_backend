<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly OtpService $otp,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Those credentials don\'t match an account.']);
        }

        // Correct password, but the signup was never finished — send a
        // fresh code (never the same one twice) and tell the frontend to
        // show the OTP screen instead of a token. No session is issued.
        if (! $user->email_verified_at) {
            $this->otp->issue($user->email);

            return response()->json([
                'message' => 'Verify your email first — we just sent you a new code.',
                'verification_required' => true,
                'email' => $user->email,
            ], 403);
        }

        return response()->json([
            'token' => $this->jwt->issue($user, 'user')['token'],
            'user' => $user->only('id', 'name', 'email', 'company_id'),
        ]);
    }

    public function destroy(Request $request)
    {
        $this->jwt->revoke($request->attributes->get('jwt_claims'));

        return response()->noContent();
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('company.wallet');

        return response()->json([
            'user' => $user->only('id', 'name', 'email'),
            'company' => $user->company,
        ]);
    }

    /**
     * Account settings screen — the company's own profile, plus an
     * optional password change for the logged-in user.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255', 'unique:companies,contact_email,'.$company->id],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);

        $company->update(['name' => $data['company_name'], 'contact_email' => $data['contact_email']]);

        if (! empty($data['password'])) {
            $user->password = $data['password']; // the model's cast hashes it
            $user->save();
        }

        return response()->json(['company' => $company->fresh()]);
    }
}
