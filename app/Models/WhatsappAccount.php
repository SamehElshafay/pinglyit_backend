<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappAccount extends Model
{
    protected $fillable = [
        'company_id', 'waba_id', 'phone_number_id', 'registration_pin', 'access_token', 'phone_number', 'status', 'connected_at',
        'ai_autoreply_enabled', 'ai_autoreply_model', 'ai_autoreply_system_prompt', 'ai_commerce_enabled',
        'ai_agent_profile', 'app_secret',
    ];

    protected $hidden = ['registration_pin', 'access_token', 'app_secret'];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'registration_pin' => 'encrypted',
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'ai_agent_profile' => 'array',
            'ai_autoreply_enabled' => 'boolean',
            'ai_commerce_enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
