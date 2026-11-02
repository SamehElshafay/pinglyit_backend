<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kling-style models start each shot from a painted first frame.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            $table->string('keyframe_path')->nullable()->after('file_path');
            $table->decimal('keyframe_cost', 12, 6)->nullable()->after('keyframe_path');
        });
    }

    public function down(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            $table->dropColumn(['keyframe_path', 'keyframe_cost']);
        });
    }
};
