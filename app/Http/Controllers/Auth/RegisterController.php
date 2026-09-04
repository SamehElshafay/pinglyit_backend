<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\Company;
use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class RegisterController extends Controller
{
    public function __construct(private readonly JwtService $jwt) {}

    /**
     * Company signup — creates the Company, its first User, and an empty
     * Wallet in one go. One signup = the whole shared account (docs §1).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:companies,contact_email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        [$company, $user] = DB::transaction(function () use ($data) {
            $company = Company::create([
                'name' => $data['company_name'],
                'contact_email' => $data['email'],
            ]);

            $company->wallet()->create(['balance' => 0, 'currency' => 'USD']);

            $user = $company->users()->create([
                'name' => $data['company_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            return [$company, $user];
        });

        $user->setRelation('company', $company); // avoids an extra query — the mail template greets them by company name

        // MAIL_MAILER=log until real SMTP creds exist — same
        // fail-clean-when-unconfigured pattern as PasswordResetMail.
        Mail::to($user->email)->send(new WelcomeMail($user));

        return response()->json([
            'token' => $this->jwt->issue($user, 'user')['token'],
            'user' => $user->only('id', 'name', 'email'),
            'company' => $company->only('id', 'name', 'status'),
        ], 201);
    }
}
