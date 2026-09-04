<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleAuthService;
use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use RuntimeException;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleAuthService $google,
        private readonly JwtService $jwt,
    ) {}

    /**
     * Public — user_website needs this before anyone is logged in, to know
     * whether to render the Google button at all and, if so, which Client
     * ID to initialize Google Identity Services with. Only ever returns
     * the Client ID itself, never anything from platform_settings that
     * isn't this — see GoogleAuthService's docblock for why a Client ID is
     * safe to expose.
     */
    public function config()
    {
        return response()->json([
            'configured' => $this->google->isConfigured(),
            'client_id' => $this->google->clientId(),
        ]);
    }

    /**
     * Exchanges a Google ID token (from the frontend's Google Sign-In
     * button) for a normal Pingly session — same response shape as
     * LoginController::store(), so the frontend handles both identically.
     */
    public function store(Request $request)
    {
        $data = $request->validate(['credential' => ['required', 'string']]);

        try {
            $user = $this->google->resolveUser($data['credential']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'token' => $this->jwt->issue($user, 'user')['token'],
            'user' => $user->only('id', 'name', 'email', 'company_id'),
        ]);
    }
}
