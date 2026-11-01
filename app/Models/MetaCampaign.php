<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaCampaign extends Model
{
    protected $fillable = [
        'company_id', 'goal', 'brief', 'plan', 'video_project_id', 'ai_image_id', 'status',
        'campaign_id', 'adset_id', 'ad_id', 'error',
    ];

    protected function casts(): array
    {
        return ['plan' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    public function aiImage(): BelongsTo
    {
        return $this->belongsTo(AiImage::class);
    }
}
