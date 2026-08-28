<?php

namespace App\Models;

use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageEvent extends Model
{
    protected $fillable = [
        'company_id', 'service_type', 'raw_cost_to_pingly',
        'billed_amount_to_client', 'multiplier_or_margin_applied', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'raw_cost_to_pingly' => 'decimal:6',
            'billed_amount_to_client' => 'decimal:6',
            'multiplier_or_margin_applied' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
