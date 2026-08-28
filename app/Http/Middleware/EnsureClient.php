<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirror of EnsureAdmin — keeps an admin's token out of the client-facing
 * /api/* routes (a client User must also belong to a company to do anything).
 */
class EnsureClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->company_id) {
            abort(403, 'Client account required.');
        }

        return $next($request);
    }
}
