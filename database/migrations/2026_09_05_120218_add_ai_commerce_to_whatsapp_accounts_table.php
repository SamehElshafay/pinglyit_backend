<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A separate mode from ai_autoreply_enabled, not a variant of it — see
     * AiCommerceAgentService's docblock for the distinction the product
     * decision drew: plain auto-reply is stateless Q&A, this is a
     * multi-turn agent that knows the company's catalog and can place/
     * confirm orders with real tool calls. The two are mutually exclusive
     * per number (WhatsappWebhookController checks commerce first).
     * ai_autoreply_model is reused for either mode — model choice is
     * orthogonal to which one is active.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->boolean('ai_commerce_enabled')->default(false)->after('ai_autoreply_system_prompt');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('ai_commerce_enabled');
        });
    }
};
