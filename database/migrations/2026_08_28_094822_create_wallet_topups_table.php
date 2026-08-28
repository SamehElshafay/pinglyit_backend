<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A real-money credit into a wallet, one row per payment provider
     * transaction. `provider_reference` (the provider's own session/charge
     * id) is unique — that's what makes crediting the wallet from a
     * webhook idempotent even if the provider retries delivery.
     */
    public function up(): void
    {
        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('stripe');
            $table->string('provider_reference')->unique();
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('pending'); // pending | completed | failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');
    }
};
