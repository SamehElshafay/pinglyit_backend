<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RevokedToken extends Model
{
    protected $fillable = ['jti', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public static function isRevoked(string $jti): bool
    {
        return static::where('jti', $jti)->exists();
    }
}
