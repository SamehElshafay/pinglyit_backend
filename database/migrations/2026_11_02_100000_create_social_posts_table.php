<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One publish of a finished film to one place (the client's Facebook
     * Page, or their Instagram as a Reel), through their own Meta
     * connection. Instagram processes a video before it can go live, so a
     * post can sit in 'processing' for a minute or two; the scheduler
     * (meta:publish-posts) finishes it.
     *
     * status: processing | published | failed
     */
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 16);               // facebook | instagram
            $table->text('caption')->nullable();
            $table->string('status', 16)->default('processing');
            $table->string('container_id')->nullable();   // Instagram media container, before publishing
            $table->string('external_id')->nullable();    // the live post / video / media id
            $table->string('permalink')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
