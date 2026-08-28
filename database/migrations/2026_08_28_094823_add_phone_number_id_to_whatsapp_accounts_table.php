<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta's Cloud API addresses a number by its numeric phone_number_id,
     * not the human-readable phone_number — needed to actually call
     * POST /{phone_number_id}/messages.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->string('phone_number_id')->nullable()->after('waba_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('phone_number_id');
        });
    }
};
