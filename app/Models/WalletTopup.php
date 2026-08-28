<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTopup extends Model
{
    protected $fillable = ['company_id', 'provider', 'provider_reference', 'amount', 'currency', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
