<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * JWTs are stateless by design, so "log out" needs a small blocklist —
     * one row per revoked token's `jti` claim, kept only until it would
     * have expired anyway. AuthenticateJwt checks this on every request.
     */
    public function up(): void
    {
        Schema::create('revoked_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('jti')->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_tokens');
    }
};
