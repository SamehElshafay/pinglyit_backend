<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A connected WhatsApp number per company (per-client WABA via
     * Embedded Signup — confirmed, docs §3.5's open decision is settled).
     * `company_id` is not unique so a company can connect more than one
     * number later without a schema change.
     */
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('waba_id')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('status')->default('pending'); // pending | connected | disconnected
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
