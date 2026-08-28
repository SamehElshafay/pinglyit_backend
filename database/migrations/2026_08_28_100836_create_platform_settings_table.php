<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational secrets the admin manages from the dashboard (starting
     * with the OpenRouter key) instead of .env — a generic key/value store
     * so the next one (a Meta token, a Stripe key) needs no new migration,
     * just a new key. `value` is encrypted at rest via the model cast.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
