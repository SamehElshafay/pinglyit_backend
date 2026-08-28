<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Payments\PaymobGateway;
use Illuminate\Http\Request;

/**
 * Paymob's three keys are entered here, not .env — admin-managed, encrypted
 * at rest (platform_settings.value), same pattern as every other connection
 * screen. Raw values are never sent back to the browser once saved, only a
 * masked preview.
 */
class PaymobConnectionController extends Controller
{
    public function __construct(private readonly PaymobGateway $paymob) {}

    public function show()
    {
        return response()->json([
            'public_key' => $this->preview($this->paymob->publicKey()),
            'secret_key' => $this->preview($this->paymob->secretKey()),
            'hmac_secret' => $this->preview($this->paymob->hmacSecret()),
            'configured' => $this->paymob->isConfigured(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'paymob_public_key' => ['sometimes', 'string', 'min:6'],
            'paymob_secret_key' => ['sometimes', 'string', 'min:6'],
            'paymob_hmac_secret' => ['sometimes', 'string', 'min:6'],
        ]);

        if ($data === []) {
            return response()->json(['message' => 'Nothing to save.'], 422);
        }

        foreach ($data as $key => $value) {
            PlatformSetting::set($key, $value);
        }

        AuditLog::record($request->user(), 'paymob_connection.update', 'Updated Paymob key(s): '.implode(', ', array_keys($data)));

        return $this->show();
    }

    public function destroy(Request $request)
    {
        foreach (['paymob_public_key', 'paymob_secret_key', 'paymob_hmac_secret'] as $key) {
            PlatformSetting::set($key, null);
        }

        AuditLog::record($request->user(), 'paymob_connection.destroy', 'Removed all Paymob keys');

        return $this->show();
    }

    private function preview(?string $value): array
    {
        return [
            'configured' => filled($value),
            'preview' => $value ? '••••'.substr($value, -4) : null,
        ];
    }
}
