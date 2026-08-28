<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per month — a daily snapshot (see ReconcileAiBilling) comparing
 * Pingly's own recorded AI Gateway spend against OpenRouter's own /credits
 * total. Written by the scheduled command, read by ReconciliationController.
 */
class BillingReconciliation extends Model
{
    protected $fillable = [
        'period', 'internal_cost', 'openrouter_reported_usage', 'drift', 'status', 'note', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            // 'period' is a plain 'YYYY-MM' string, deliberately not a date
            // cast — see the migration's comment for why.
            'internal_cost' => 'decimal:6',
            'openrouter_reported_usage' => 'decimal:6',
            'drift' => 'decimal:6',
            'checked_at' => 'datetime',
        ];
    }
}
