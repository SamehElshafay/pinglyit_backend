<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappConversation extends Model
{
    protected $fillable = ['company_id', 'customer_phone', 'messages'];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
