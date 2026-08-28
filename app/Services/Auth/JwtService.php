<?php

namespace App\Services\Auth;

use App\Models\AdminUser;
use App\Models\RevokedToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use stdClass;

/**
 * Issues and verifies the platform's JWTs. Two subject types share this
 * one service — 'admin' (AdminUser) and 'user' (User) — the `type` claim
 * is what AuthenticateJwt uses to load the right model and what
 * EnsureAdmin/EnsureClient check against, so a client's token can never
 * be replayed against an admin route or vice versa.
 */
class JwtService
{
    public function issue(Authenticatable $subject, string $type): array
    {
        $now = Carbon::now();
        $expiresAt = $now->clone()->addMinutes(config('jwt.ttl'));
        $jti = (string) Str::uuid();

        $claims = [
            'sub' => $subject->getAuthIdentifier(),
            'type' => $type,
            'jti' => $jti,
            'iat' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
        ];

        if ($type === 'user' && $subject instanceof User) {
            $claims['company_id'] = $subject->company_id;
        }

        $token = JWT::encode($claims, config('jwt.secret'), config('jwt.algo'));

        return ['token' => $token, 'jti' => $jti, 'expires_at' => $expiresAt];
    }

    /**
     * @throws \Firebase\JWT\ExpiredException|\UnexpectedValueException on an invalid/expired token
     */
    public function decode(string $token): stdClass
    {
        return JWT::decode($token, new Key(config('jwt.secret'), config('jwt.algo')));
    }

    public function resolveSubject(stdClass $claims): AdminUser|User|null
    {
        return match ($claims->type ?? null) {
            'admin' => AdminUser::find($claims->sub),
            'user' => User::find($claims->sub),
            default => null,
        };
    }

    public function isRevoked(stdClass $claims): bool
    {
        return RevokedToken::isRevoked($claims->jti ?? '');
    }

    public function revoke(stdClass $claims): void
    {
        RevokedToken::firstOrCreate(
            ['jti' => $claims->jti],
            ['expires_at' => Carbon::createFromTimestamp($claims->exp)],
        );
    }
}
