<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 2-step-verification PIN Meta requires on POST /{phone_number_id}/register
     * (WhatsAppEmbeddedSignupService::registerPhoneNumber()) — generated once per
     * number at connect time, never chosen by the client. Kept (encrypted) only
     * because Meta may ask for it again if the number ever needs re-registering
     * (e.g. after being deregistered); it's never displayed or sent anywhere else.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('registration_pin')->nullable()->after('phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('registration_pin');
        });
    }
};
