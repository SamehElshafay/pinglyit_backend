<?php

namespace App\Http\Middleware;

use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Replaces auth:sanctum. Verifies the Bearer token, checks it against the
 * revoked_tokens blocklist, then resolves the actual AdminUser/User and
 * makes it available via $request->user() exactly like Sanctum did — so
 * every controller written against $request->user() needed zero changes.
 */
class AuthenticateJwt
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            abort(401, 'Missing bearer token.');
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (Throwable) {
            // Covers every way firebase/php-jwt rejects a token — expired,
            // bad signature, malformed segments, wrong algorithm, etc.
            // All of them mean the same thing to the client: log in again.
            abort(401, 'Invalid or expired token.');
        }

        if ($this->jwt->isRevoked($claims)) {
            abort(401, 'Token has been revoked.');
        }

        $subject = $this->jwt->resolveSubject($claims);

        if (! $subject) {
            abort(401, 'Token subject no longer exists.');
        }

        $request->setUserResolver(fn () => $subject);
        $request->attributes->set('jwt_claims', $claims);

        return $next($request);
    }
}
