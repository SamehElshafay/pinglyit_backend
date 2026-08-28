<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed secrets (docs decision: OpenRouter key lives here, entered
 * from the admin dashboard — not .env). `value` is encrypted at rest.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::where('key', $key)->first()?->value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        if (blank($value)) {
            static::where('key', $key)->delete();

            return;
        }

        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
