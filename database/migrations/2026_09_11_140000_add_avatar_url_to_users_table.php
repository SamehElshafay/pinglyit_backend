<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A profile picture URL — only ever populated from Google's `picture`
     * claim on a Google sign-in (see GoogleAuthService). Nobody uploads one
     * directly; a password-only account just stays null and the frontend
     * falls back to an initials avatar, same as the admin dashboard already
     * does for the shared admin role.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_url')->nullable()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });
    }
};
