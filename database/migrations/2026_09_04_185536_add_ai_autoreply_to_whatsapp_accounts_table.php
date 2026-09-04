<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a company have Pingly auto-generate a WhatsApp reply via the AI
     * Gateway for every inbound text message on this number — see
     * WhatsappAutoReplyService. Lives on the account (not company-wide
     * ServiceConfig) because auto-reply is inherently per connected number,
     * not a pricing setting.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->boolean('ai_autoreply_enabled')->default(false);
            $table->string('ai_autoreply_model')->nullable();
            $table->text('ai_autoreply_system_prompt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['ai_autoreply_enabled', 'ai_autoreply_model', 'ai_autoreply_system_prompt']);
        });
    }
};
