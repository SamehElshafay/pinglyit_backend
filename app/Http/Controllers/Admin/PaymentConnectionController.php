<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\Payments\TapGateway;
use Illuminate\Http\Request;

/**
 * The Tap Payments key is entered here, not .env — admin-managed, encrypted
 * at rest (platform_settings.value), same pattern as AiConnectionController.
 * The raw key is never sent back to the browser once saved.
 */
class PaymentConnectionController extends Controller
{
    public function __construct(private readonly TapGateway $tap) {}

    public function show()
    {
        $key = $this->tap->secretKey();

        return response()->json([
            'configured' => filled($key),
            'preview' => $key ? '••••'.substr($key, -4) : null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['tap_secret_key' => ['required', 'string', 'min:10']]);

        PlatformSetting::set('tap_secret_key', $data['tap_secret_key']);

        return $this->show();
    }

    public function destroy()
    {
        PlatformSetting::set('tap_secret_key', null);

        return $this->show();
    }
}
