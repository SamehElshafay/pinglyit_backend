<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Created by the AI Commerce Assistant's create_order tool (see
     * AiCommerceAgentService) mid-conversation — starts life as
     * 'pending_confirmation' and only becomes 'confirmed' once the AI's
     * confirm_order tool fires, which it's instructed to only do after the
     * customer explicitly agrees. No payment/inventory concept yet — this
     * is order *tracking*, not a checkout.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->enum('status', ['pending_confirmation', 'confirmed', 'cancelled'])->default('pending_confirmation');
            $table->decimal('total', 12, 4);
            $table->string('currency', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_phone']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
