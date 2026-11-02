<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pro mode: every shot is animated from an AI-painted, product-accurate
// first frame, and the film's last shot lands on a painted hero frame.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_projects', function (Blueprint $table) {
            $table->boolean('keyframes')->default(false)->after('style');
        });
        Schema::table('video_shots', function (Blueprint $table) {
            $table->string('end_frame_path')->nullable()->after('keyframe_path');
        });
    }

    public function down(): void
    {
        Schema::table('video_projects', fn (Blueprint $table) => $table->dropColumn('keyframes'));
        Schema::table('video_shots', fn (Blueprint $table) => $table->dropColumn('end_frame_path'));
    }
};
