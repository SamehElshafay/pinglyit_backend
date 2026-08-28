<?php

namespace App\Models;

use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row = one pricing config, for one service, either platform-wide
 * (company_id null) or for one client (company_id set). See docs §5.
 */
class ServiceConfig extends Model
{
    protected $fillable = ['company_id', 'service_type', 'pricing_config'];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'pricing_config' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopePlatformDefault($query)
    {
        return $query->whereNull('company_id');
    }
}
