<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // e.g. 'ai_pricing.update' — machine-stable, for filtering later
            $table->text('description'); // human-readable summary shown on the Audit log screen
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable(); // whatever changed — never a secret value itself
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
