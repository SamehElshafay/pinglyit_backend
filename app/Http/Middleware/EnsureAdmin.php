<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A valid JWT proves *someone* is authenticated — it doesn't say
 * which model. This is the check that keeps a client's User token out of
 * every /api/admin/* route, and vice versa (paired with EnsureClient).
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof AdminUser) {
            abort(403, 'Admin access required.');
        }

        return $next($request);
    }
}
