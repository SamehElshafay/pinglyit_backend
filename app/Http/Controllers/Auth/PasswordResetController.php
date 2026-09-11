<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\Mail\TransactionalMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Client (user_website) self-service password reset. Reuses Laravel's
 * default `password_reset_tokens` table — scoped to the `User` model only,
 * there's no admin-side equivalent yet (single shared admin, reset by hand).
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    /**
     * Always returns the same message whether or not the email exists —
     * this endpoint never reveals account existence.
     */
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($token), 'created_at' => now()],
            );

            $resetUrl = rtrim(config('pingly.frontend_url'), '/')
                .'/reset-password?email='.urlencode($user->email).'&token='.$token;

            // Best-effort on purpose: a send that throws (Resend refuses
            // any recipient until a sending domain is verified) must not
            // change the response, or "does this email exist?" becomes
            // answerable by whoever notices 200-vs-500. The failure goes
            // to the log, which is where a broken mailer belongs.
            $this->mailer->attempt($user->email, new PasswordResetMail($resetUrl));
        }

        return response()->json(['message' => 'If that email has an account, a reset link is on its way.']);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid.']);
        }

        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            throw ValidationException::withMessages(['token' => 'This reset link has expired — request a new one.']);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => 'Those credentials don\'t match an account.']);
        }

        $user->password = $data['password']; // the model's cast hashes it
        $user->save();

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return response()->json(['message' => 'Password updated — log in with your new password.']);
    }
}
