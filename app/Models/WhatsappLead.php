<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappLead extends Model
{
    public const STAGES = ['new', 'exploring', 'interested', 'negotiating', 'ready_to_buy', 'won', 'lost'];

    public const TEMPERATURES = ['hot', 'warm', 'cold', 'time_waster'];

    protected $fillable = [
        'company_id', 'customer_phone', 'customer_name', 'stage', 'temperature', 'intent_score', 'buyer_style',
        'summary', 'needs', 'objections', 'next_action', 'ai_paused', 'handoff_reason', 'messages_count',
        'last_message_at', 'assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'needs' => 'array',
            'objections' => 'array',
            'ai_paused' => 'boolean',
            'last_message_at' => 'datetime',
            'assessed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function for(int $companyId, string $phone): self
    {
        return static::firstOrCreate(['company_id' => $companyId, 'customer_phone' => $phone]);
    }
}
