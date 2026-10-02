<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every WhatsApp message that passes through Pingly, in or out.
     *
     * Deliberately separate from whatsapp_conversations: that table is the
     * AI's prompt memory — a capped JSON blob holding the last 20 turns so
     * the model has context without growing the bill. This is the record a
     * person reads and an API serves, so it keeps every message, one row
     * each, queryable by customer and by time.
     */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone');
            $table->string('direction', 8);            // inbound | outbound
            $table->string('type', 24)->default('text');
            $table->text('body')->nullable();

            // Meta's own id (wamid.…), so a delivery-status webhook can find
            // the row later. Nullable: an outbound send that Meta refuses
            // never gets one, and we still want the attempt on the record.
            $table->string('wa_message_id')->nullable()->index();

            // sent | delivered | read | failed for outbound; received for
            // inbound. Free-form rather than an enum because Meta adds
            // statuses faster than a migration can keep up.
            $table->string('status', 16)->nullable();
            $table->text('error')->nullable();

            // Who produced an outbound message: ai_autoreply | ai_commerce |
            // api | dashboard. Null for inbound. Worth keeping — "did a
            // human or the AI say this" is the first question asked when a
            // customer complains about a reply.
            $table->string('sent_by', 16)->nullable();

            $table->timestamps();

            // The inbox query: one customer's thread, newest first.
            $table->index(['company_id', 'customer_phone', 'id']);
            // The list-of-chats query, and the 24-hour window check.
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
