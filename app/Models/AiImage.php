<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiImage extends Model
{
    protected $fillable = ['company_id', 'prompt', 'model', 'aspect_ratio', 'path', 'billed_amount'];

    protected function casts(): array
    {
        return ['billed_amount' => 'decimal:6'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
