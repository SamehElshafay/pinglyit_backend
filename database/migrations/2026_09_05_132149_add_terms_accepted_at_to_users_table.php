<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A timestamped consent record, not just a UI checkbox — RegisterController::store()
     * requires this to be explicitly accepted and stamps the real time, which is what
     * actually makes "the user agreed to the Terms" defensible later. Google sign-in
     * doesn't collect this explicitly (one-click signup has no form to put a checkbox
     * on) — TermsPage/PrivacyPage are linked as a passive disclaimer near that button
     * instead, same pattern most one-click-OAuth signups use.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
        });

        // Deliberately no backfill: every account that already exists signed up
        // before these terms existed to agree to, so stays null rather than
        // misrepresenting a consent that never happened.
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('terms_accepted_at');
        });
    }
};
