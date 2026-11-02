<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoFaceSwap extends Model
{
    protected $fillable = [
        'video_project_id', 'company_id', 'user_id', 'face_path', 'source_path', 'target_face_index', 'status',
        'task_id', 'frames', 'quoted_amount', 'raw_cost', 'billed_amount', 'usage_event_id', 'output_path',
        'error', 'consented_at', 'last_polled_at',
    ];

    protected function casts(): array
    {
        return [
            'quoted_amount' => 'decimal:6',
            'raw_cost' => 'decimal:6',
            'billed_amount' => 'decimal:6',
            'consented_at' => 'datetime',
            'last_polled_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }
}
