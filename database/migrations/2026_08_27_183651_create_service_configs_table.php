<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `service_configs` from docs §5 — one shape for every service's pricing.
     *
     * `company_id` null = the platform-wide default for that service_type.
     * `company_id` set = that one client's override, independent of the
     * default (docs §2.1: admin must be able to override any client
     * individually, e.g. Client X's AI multiplier while everyone else stays
     * on the platform default).
     *
     * A new service (Service C, D, ...) needs zero schema changes — it just
     * writes rows here with its own `service_type` and its own
     * `pricing_config` shape (docs §5).
     */
    public function up(): void
    {
        Schema::create('service_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('service_type'); // whatsapp | ai | ...
            $table->json('pricing_config');
            $table->timestamps();

            // Guards per-client override uniqueness at the DB level. The
            // "only one platform-default row per service_type" half of this
            // (company_id IS NULL) is enforced in ServiceConfigRepository,
            // since most DBs treat NULLs as distinct in a unique index.
            $table->unique(['company_id', 'service_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_configs');
    }
};
