<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisterController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Company signup — creates the Company, its first User (unverified),
     * and an empty Wallet in one go. No token yet: the account only
     * becomes usable once VerifyOtpController::verify() confirms the code
     * this sends — see the email_otps migration's docblock for why every
     * account created before this feature shipped is exempt.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:companies,contact_email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        DB::transaction(function () use ($data) {
            $company = Company::create([
                'name' => $data['company_name'],
                'contact_email' => $data['email'],
            ]);

            $company->wallet()->create(['balance' => 0, 'currency' => 'USD']);

            $company->users()->create([
                'name' => $data['company_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
        });

        $this->otp->issue($data['email']);

        return response()->json([
            'message' => 'Almost there — enter the verification code we just emailed you.',
            'email' => $data['email'],
        ], 201);
    }
}
