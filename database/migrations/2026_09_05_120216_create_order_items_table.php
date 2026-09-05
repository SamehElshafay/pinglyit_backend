<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * product_name/unit_price are a snapshot at order time (the product's
     * price — or a discounted price, see Product::effectivePrice() — can
     * change or the product can be deleted later; the order must still
     * read correctly). product_id is kept for reference but nullable and
     * not cascade-deleted with the product.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->nullOnDelete();
            $table->string('product_name');
            $table->decimal('unit_price', 12, 4);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
