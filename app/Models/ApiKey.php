<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = ['company_id', 'label', 'key_prefix', 'key_hash', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Create a key and return the one-time plaintext alongside the model —
     * only the hash is ever persisted.
     */
    public static function generate(Company $company, string $label): array
    {
        $plaintext = 'pk_live_'.Str::random(32);

        $key = static::create([
            'company_id' => $company->id,
            'label' => $label,
            'key_prefix' => substr($plaintext, 0, 12),
            'key_hash' => Hash::make($plaintext),
        ]);

        return [$key, $plaintext];
    }

    public static function findByPlaintext(string $plaintext): ?self
    {
        return static::whereNull('revoked_at')
            ->where('key_prefix', substr($plaintext, 0, 12))
            ->get()
            ->first(fn (self $key) => Hash::check($plaintext, $key->key_hash));
    }
}
