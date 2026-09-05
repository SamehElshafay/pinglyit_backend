<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company's own catalog — what the AI Commerce Assistant (see
     * AiCommerceAgentService) references when chatting with a customer on
     * WhatsApp, and what create_order snapshots into order_items. Managed
     * by the company itself, either from the dashboard or its own API key
     * (see Client\ProductController / Gateway\ProductController) — nothing
     * here is admin-managed.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 4);
            $table->string('currency', 3)->default('USD');
            $table->string('sku')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
