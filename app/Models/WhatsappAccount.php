<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappAccount extends Model
{
    protected $fillable = [
        'company_id', 'waba_id', 'phone_number_id', 'registration_pin', 'phone_number', 'status', 'connected_at',
        'ai_autoreply_enabled', 'ai_autoreply_model', 'ai_autoreply_system_prompt', 'ai_commerce_enabled',
    ];

    protected $hidden = ['registration_pin'];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'registration_pin' => 'encrypted',
            'ai_autoreply_enabled' => 'boolean',
            'ai_commerce_enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
