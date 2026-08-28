<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        return response()->json([
            'configured' => filled($key),
            'preview' => $key ? '••••'.substr($key, -4) : null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['openrouter_api_key' => ['required', 'string', 'min:10']]);

        PlatformSetting::set('openrouter_api_key', $data['openrouter_api_key']);

        return $this->show();
    }

    public function destroy()
    {
        PlatformSetting::set('openrouter_api_key', null);

        return $this->show();
    }
}
