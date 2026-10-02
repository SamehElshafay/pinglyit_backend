<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a company's products come from, when they come from the
     * company's own system rather than being typed in here.
     *
     * One row per company: Pingly calls their endpoint, maps the response
     * onto products, and keeps them in step. Most businesses already have
     * their catalog somewhere — asking them to retype it into a second
     * system is how a catalog goes stale within a week, and a stale catalog
     * means the AI quotes prices that no longer exist.
     */
    public function up(): void
    {
        Schema::create('catalog_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('url', 2048);
            $table->string('method', 8)->default('GET');

            // Encrypted: this is a credential for the client's own system,
            // and Pingly holding it in the clear would be worse than holding
            // its own. Nullable because a public endpoint needs none.
            $table->text('auth_token')->nullable();
            // Both nullable, not just defaulted: plenty of APIs want a bare
            // token under their own header ("X-API-Key: abc") with no prefix
            // at all, and Laravel converts an empty form field to null before
            // it reaches here — so NOT NULL would reject a legitimate setup.
            $table->string('auth_header')->nullable()->default('Authorization');
            $table->string('auth_prefix')->nullable()->default('Bearer');

            // Extra query parameters, as the client's API expects them.
            $table->json('query_params')->nullable();

            // Where the array of products sits in the response, dot notation
            // ("data.items"), and which field of each item maps to what.
            // Without this every client would need their own importer.
            $table->string('items_path')->nullable();
            $table->json('field_map')->nullable();

            $table->boolean('auto_sync')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_imported_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sources');
    }
};
