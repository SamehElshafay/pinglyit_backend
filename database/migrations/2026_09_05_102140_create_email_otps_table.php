<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same shape as password_reset_tokens (one row per email, hashed
     * secret, plain created_at for expiry math) plus `attempts` — a wrong
     * guess is far more guessable here (a 6-digit code) than a random
     * 64-char reset token, so failed tries are counted and capped
     * (VerifyOtpController) rather than left open-ended.
     */
    public function up(): void
    {
        Schema::create('email_otps', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('otp');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        // Email verification is new — every account that already exists
        // today signed up before this feature did, so it must not suddenly
        // get locked out of its own account. Only accounts created from
        // here on start out unverified.
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otps');
    }
};
