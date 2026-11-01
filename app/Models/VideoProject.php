<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoProject extends Model
{
    public const ACTIVE_STATUSES = ['rendering', 'stitching'];

    protected $fillable = [
        'company_id', 'user_id', 'title', 'prompt', 'model', 'resolution', 'aspect_ratio',
        'target_duration', 'generate_audio', 'language', 'style', 'references', 'anchor_path',
        'storyboard', 'status', 'estimated_cost', 'billed_total', 'error', 'output_path',
        'thumbnail_path', 'output_duration', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'generate_audio' => 'boolean',
            'references' => 'array',
            'storyboard' => 'array',
            'estimated_cost' => 'decimal:6',
            'billed_total' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shots(): HasMany
    {
        return $this->hasMany(VideoShot::class)->orderBy('position');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** Folder on the private disk holding everything this project produced. */
    public function storageDir(): string
    {
        return "video/{$this->company_id}/{$this->id}";
    }
}
