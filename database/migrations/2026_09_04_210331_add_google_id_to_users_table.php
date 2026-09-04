<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links a User to their Google account's stable `sub` claim, once
     * they've signed in with Google at least once — see GoogleAuthService.
     * Nullable/unique: most users never touch this, and it's only ever
     * matched by email first regardless (see GoogleAuthService's docblock
     * for why), so this is a secondary safety net, not the primary key.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_id');
        });
    }
};
