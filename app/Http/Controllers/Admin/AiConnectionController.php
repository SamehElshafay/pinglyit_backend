<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Ai\AiGatewayService;
use Illuminate\Http\Request;

/**
 * The OpenRouter key is entered here, not .env — admin-managed, encrypted
 * at rest (platform_settings.value). The raw key is never sent back to the
 * browser once saved, only a masked preview.
 */
class AiConnectionController extends Controller
{
    public function __construct(private readonly AiGatewayService $ai) {}

    public function show()
    {
        $key = $this->ai->apiKey();

        $faceKey = PlatformSetting::get('piapi_api_key');

        return response()->json([
            'configured' => filled($key),
            'preview' => $key ? '••••'.substr($key, -4) : null,
            'faceswap_configured' => filled($faceKey),
            'faceswap_preview' => $faceKey ? '••••'.substr($faceKey, -4) : null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['openrouter_api_key' => ['required', 'string', 'min:10']]);

        PlatformSetting::set('openrouter_api_key', $data['openrouter_api_key']);
        AuditLog::record($request->user(), 'ai_connection.update', 'Updated the OpenRouter API key');

        return $this->show();
    }

    /** PiAPI key for video face swap — same rules as the OpenRouter key. */
    public function updateFaceSwap(Request $request)
    {
        $data = $request->validate(['piapi_api_key' => ['nullable', 'string', 'min:10']]);

        PlatformSetting::set('piapi_api_key', $data['piapi_api_key'] ?? null);
        AuditLog::record($request->user(), 'faceswap_connection.update', filled($data['piapi_api_key'] ?? null) ? 'Updated the PiAPI face swap key' : 'Removed the PiAPI face swap key');

        return $this->show();
    }

    public function destroy(Request $request)
    {
        PlatformSetting::set('openrouter_api_key', null);
        AuditLog::record($request->user(), 'ai_connection.destroy', 'Removed the OpenRouter API key');

        return $this->show();
    }
}
