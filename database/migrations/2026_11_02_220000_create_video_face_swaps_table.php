<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Face swap: put a consenting real person's face onto a finished film's actor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_face_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('face_path');
            $table->string('source_path')->nullable();
            $table->unsignedTinyInteger('target_face_index')->default(0);
            $table->string('status')->default('pending'); // pending → processing → completed | failed
            $table->string('task_id')->nullable();
            $table->unsignedInteger('frames')->default(0);
            $table->decimal('quoted_amount', 12, 6)->default(0);
            $table->decimal('raw_cost', 12, 6)->nullable();
            $table->decimal('billed_amount', 12, 6)->nullable();
            $table->foreignId('usage_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('output_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('consented_at');
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_polled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_face_swaps');
    }
};
