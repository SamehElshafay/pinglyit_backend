<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoShot extends Model
{
    protected $fillable = [
        'video_project_id', 'position', 'duration', 'description', 'rendered_prompt', 'status',
        'provider_job_id', 'attempts', 'submitted_at', 'last_polled_at', 'quoted_amount', 'raw_cost',
        'billed_amount', 'usage_event_id', 'file_path', 'keyframe_path', 'end_frame_path', 'keyframe_cost', 'error',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'quoted_amount' => 'decimal:6',
            'raw_cost' => 'decimal:6',
            'billed_amount' => 'decimal:6',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }
}
