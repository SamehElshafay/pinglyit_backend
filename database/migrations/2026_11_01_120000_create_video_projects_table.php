<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Video Studio. A project is one film a client ordered; it is rendered
     * as a sequence of shots (no model renders more than ~30s in one go),
     * each shot its own provider job, then joined into one file.
     *
     * Lifecycle (video_projects.status):
     *   draft      — storyboard written, waiting for the client to approve it
     *   rendering  — shots being rendered
     *   stitching  — every shot is in, joining them
     *   completed  — output_path is the finished film
     *   failed     — a shot ran out of retries (client paid only for shots
     *                that rendered, and can resume)
     */
    public function up(): void
    {
        Schema::create('video_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title')->nullable();
            $table->text('prompt');

            // Creative settings, as the client chose them.
            $table->string('model');
            $table->string('resolution', 8);
            $table->string('aspect_ratio', 8);
            $table->unsignedSmallInteger('target_duration');
            $table->boolean('generate_audio')->default(true);
            $table->string('language', 16)->nullable();   // dialogue/voiceover language, null = none
            $table->string('style', 32)->nullable();

            // [{path, label, role}] — stored on the private disk, sent to the
            // provider on every shot so faces/products stay the same.
            $table->json('references')->nullable();
            // A still pulled from the first shot, used as the identity
            // reference for the rest when the client uploaded none.
            $table->string('anchor_path')->nullable();

            // The director's plan: style bible, characters, shot list.
            $table->json('storyboard')->nullable();

            $table->string('status', 16)->default('draft')->index();
            $table->decimal('estimated_cost', 12, 6)->default(0);
            $table->decimal('billed_total', 12, 6)->default(0);
            $table->text('error')->nullable();

            $table->string('output_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedSmallInteger('output_duration')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'id']);
        });

        Schema::create('video_shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_project_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('duration');

            // What the client sees and may edit, and the full prompt that
            // was actually sent (style bible + characters + this shot).
            $table->text('description');
            $table->text('rendered_prompt')->nullable();

            // pending | submitted | completed | failed
            $table->string('status', 16)->default('pending');
            $table->string('provider_job_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();

            // What the client was quoted for this shot before approving —
            // the most it can ever be billed for it, whatever the provider charges.
            $table->decimal('quoted_amount', 12, 6)->default(0);
            $table->decimal('raw_cost', 12, 6)->nullable();
            $table->decimal('billed_amount', 12, 6)->nullable();
            $table->foreignId('usage_event_id')->nullable()->constrained()->nullOnDelete();

            $table->string('file_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['video_project_id', 'position']);
            $table->index(['status', 'last_polled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_shots');
        Schema::dropIfExists('video_projects');
    }
};
