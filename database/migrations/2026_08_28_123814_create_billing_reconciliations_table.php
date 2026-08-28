<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_reconciliations', function (Blueprint $table) {
            $table->id();
            // The month this snapshot was taken for, as a plain 'YYYY-MM' string
            // — not a real date column on purpose: a true `date` column round-trips
            // through Eloquent's date cast/serialization on save and then through
            // raw string comparison on updateOrCreate's lookup, which land on
            // different string shapes ('2026-08-01' vs '2026-08-01 00:00:00') on
            // SQLite (only MySQL's implicit date coercion papers over it) — this
            // sidesteps that entirely, and month-granularity is all this ever
            // needs. OpenRouter's /credits endpoint only reports a lifetime
            // running total (no historical per-month breakdown), so in practice
            // only the *current* period ever gets a real status — see
            // ReconcileAiBilling's docblock.
            $table->string('period', 7)->unique();
            $table->decimal('internal_cost', 14, 6);
            $table->decimal('openrouter_reported_usage', 14, 6)->nullable();
            $table->decimal('drift', 14, 6)->nullable();
            $table->string('status'); // matched | drifted | unavailable
            $table->text('note')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_reconciliations');
    }
};
