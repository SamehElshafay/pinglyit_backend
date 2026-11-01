<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaConnection extends Model
{
    protected $fillable = [
        'company_id', 'access_token', 'meta_user_id', 'meta_user_name', 'granted_permissions', 'token_expires_at',
        'ad_account_id', 'ad_account_name', 'currency', 'page_id', 'page_name', 'instagram_account_id',
        'instagram_username', 'status', 'last_error', 'last_checked_at',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'granted_permissions' => 'array',
            'token_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
