<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPost extends Model
{
    protected $fillable = [
        'company_id', 'video_project_id', 'ai_image_id', 'platform', 'caption', 'status', 'container_id',
        'external_id', 'permalink', 'error', 'attempts', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function aiImage(): BelongsTo
    {
        return $this->belongsTo(AiImage::class);
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }
}
