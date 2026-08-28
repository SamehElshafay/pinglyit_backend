<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every manual top-up/correction an admin makes, with who and why —
     * the audit trail for the Wallet adjustments screen (screen-map).
     */
    public function up(): void
    {
        Schema::create('wallet_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 4); // signed: +credit, -debit
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_adjustments');
    }
};
