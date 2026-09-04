<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\Request;

/**
 * The Google OAuth Client ID is entered here, not .env — same
 * PlatformSetting pattern as every other connection screen. Unlike the
 * others, this value isn't actually a secret (Google designs it to be
 * public), so it's returned in full rather than masked — see
 * GoogleAuthService's docblock.
 */
class GoogleConnectionController extends Controller
{
    public function __construct(private readonly GoogleAuthService $google) {}

    public function show()
    {
        return response()->json([
            'configured' => $this->google->isConfigured(),
            'client_id' => $this->google->clientId(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['google_oauth_client_id' => ['required', 'string', 'min:10']]);

        PlatformSetting::set('google_oauth_client_id', $data['google_oauth_client_id']);
        AuditLog::record($request->user(), 'google_connection.update', 'Updated the Google OAuth Client ID');

        return $this->show();
    }

    public function destroy(Request $request)
    {
        PlatformSetting::set('google_oauth_client_id', null);
        AuditLog::record($request->user(), 'google_connection.destroy', 'Removed the Google OAuth Client ID');

        return $this->show();
    }
}
