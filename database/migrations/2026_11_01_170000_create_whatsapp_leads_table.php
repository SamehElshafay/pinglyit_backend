<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI Sales Agent's read on each customer it talks to — one row per
     * (company, customer phone), rewritten after every reply: where they
     * are in the sale, how likely they are to buy, what they want, what's
     * stopping them, and what to do next. This is what turns an inbox into
     * a pipeline a business owner can actually work from.
     *
     * Also where a human takes a conversation over: ai_paused stops the AI
     * replying to this one customer (set automatically when someone replies
     * from the dashboard, or when the AI itself asks for a human).
     */
    public function up(): void
    {
        Schema::create('whatsapp_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone', 32);
            $table->string('customer_name')->nullable();

            // new | exploring | interested | negotiating | ready_to_buy | won | lost
            $table->string('stage', 16)->default('new');
            // hot | warm | cold | time_waster
            $table->string('temperature', 16)->default('warm');
            $table->unsignedTinyInteger('intent_score')->default(0); // 0–100
            $table->string('buyer_style', 32)->nullable();           // e.g. price_sensitive, analytical, impulsive, relationship
            $table->text('summary')->nullable();
            $table->json('needs')->nullable();
            $table->json('objections')->nullable();
            $table->text('next_action')->nullable();

            $table->boolean('ai_paused')->default(false);
            $table->string('handoff_reason')->nullable();
            $table->unsignedInteger('messages_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'customer_phone']);
            $table->index(['company_id', 'temperature', 'last_message_at']);
        });

        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            // What the Sales Agent knows about the business and how it should
            // sound — see AiCommerceAgentService::PROFILE_FIELDS.
            $table->json('ai_agent_profile')->nullable()->after('ai_commerce_enabled');
            // The client's own Meta app secret, used to verify that inbound
            // webhooks really come from Meta (X-Hub-Signature-256). Encrypted.
            $table->text('app_secret')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn(['ai_agent_profile', 'app_secret']);
        });
        Schema::dropIfExists('whatsapp_leads');
    }
};
