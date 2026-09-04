<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappAccount extends Model
{
    protected $fillable = [
        'company_id', 'waba_id', 'phone_number_id', 'phone_number', 'status', 'connected_at',
        'ai_autoreply_enabled', 'ai_autoreply_model', 'ai_autoreply_system_prompt',
    ];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'ai_autoreply_enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
