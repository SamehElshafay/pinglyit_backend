<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Image Studio: one generated ad image per row (see ImageStudioService).
        Schema::create('ai_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('model');
            $table->string('aspect_ratio', 8);
            $table->string('path')->nullable();
            $table->decimal('billed_amount', 12, 6)->default(0);
            $table->timestamps();
            $table->index(['company_id', 'id']);
        });

        // Social posts can carry an image as well as a video now.
        Schema::table('social_posts', function (Blueprint $table) {
            $table->foreignId('ai_image_id')->nullable()->after('video_project_id')->constrained('ai_images')->nullOnDelete();
        });

        // Campaigns the AI drafted and created on the client's ad account —
        // always created PAUSED; the client switches them on (see MetaCampaignBuilder).
        Schema::create('meta_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('goal', 16);                       // messages | traffic | awareness | video_views
            $table->text('brief');
            $table->json('plan');                             // the editable draft: name, budget, days, countries, ages, copy, CTA…
            $table->foreignId('video_project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_image_id')->nullable()->constrained('ai_images')->nullOnDelete();
            $table->string('status', 16)->default('draft');  // draft | created | active | paused | failed
            $table->string('campaign_id')->nullable();
            $table->string('adset_id')->nullable();
            $table->string('ad_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_campaigns');
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_image_id');
        });
        Schema::dropIfExists('ai_images');
    }
};
