<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `usage_events` from docs §5 — every billable thing that happens on
     * any service, in one shape. WhatsApp message logs and AI usage logs
     * are both just this table filtered by `service_type`.
     */
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('service_type'); // whatsapp | ai | ...
            $table->decimal('raw_cost_to_pingly', 14, 6)->default(0);
            $table->decimal('billed_amount_to_client', 14, 6)->default(0);
            $table->decimal('multiplier_or_margin_applied', 10, 4)->nullable();
            $table->json('metadata')->nullable(); // service-specific: category/country, or real_tokens/model, etc.
            $table->timestamps();

            $table->index(['company_id', 'service_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
