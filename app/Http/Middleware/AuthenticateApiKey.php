<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /v1/* — the API a client's own project calls directly, using the
 * pk_live_... key they created on the API keys screen. Nothing to do with
 * the JWT the dashboards use to log a person in; this authenticates a
 * company's *application*, not a person.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token || ! str_starts_with($token, 'pk_live_')) {
            abort(401, 'Missing or malformed API key.');
        }

        $apiKey = ApiKey::findByPlaintext($token);

        if (! $apiKey) {
            abort(401, 'Invalid or revoked API key.');
        }

        $apiKey->update(['last_used_at' => now()]);
        $request->attributes->set('company', $apiKey->company);

        return $next($request);
    }
}
