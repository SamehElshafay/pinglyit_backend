<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The multi-turn memory WhatsappAutoReplyService's docblock flagged as
     * a reasonable next step, not faked — one row per (company, customer),
     * `messages` a plain JSON array of {role, content} capped at send time
     * (see AiCommerceAgentService::MAX_HISTORY_MESSAGES) so token cost
     * doesn't grow unbounded over a long-running conversation. Only used
     * by the AI Commerce Assistant today — the simpler auto-reply mode
     * stays deliberately stateless.
     */
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone');
            // No DB-level default — MariaDB's JSON-as-LONGTEXT support for
            // expression defaults is version-dependent; WhatsappConversation
            // always sets this explicitly on create() instead.
            $table->json('messages');
            $table->timestamps();

            $table->unique(['company_id', 'customer_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
