<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Payments\CryptomusGateway;
use Illuminate\Http\Request;

/**
 * Cryptomus's Merchant ID and (Payment) API key are entered here, not
 * .env — admin-managed, encrypted at rest (platform_settings.value), same
 * pattern as every other connection screen. The Merchant ID isn't a
 * secret (an account identifier, like Paymob's Integration ID), so it's
 * returned in full; the API key is masked, same as PaymobConnectionController.
 */
class CryptomusConnectionController extends Controller
{
    public function __construct(private readonly CryptomusGateway $cryptomus) {}

    public function show()
    {
        $key = $this->cryptomus->apiKey();

        return response()->json([
            'merchant_id' => $this->cryptomus->merchantId(),
            'api_key' => $this->preview($key),
            'configured' => $this->cryptomus->isConfigured(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'cryptomus_merchant_id' => ['sometimes', 'string', 'min:6'],
            'cryptomus_api_key' => ['sometimes', 'string', 'min:10'],
        ]);

        if ($data === []) {
            return response()->json(['message' => 'Nothing to save.'], 422);
        }

        foreach ($data as $key => $value) {
            PlatformSetting::set($key, $value);
        }

        AuditLog::record($request->user(), 'cryptomus_connection.update', 'Updated Cryptomus key(s): '.implode(', ', array_keys($data)));

        return $this->show();
    }

    public function destroy(Request $request)
    {
        foreach (['cryptomus_merchant_id', 'cryptomus_api_key'] as $key) {
            PlatformSetting::set($key, null);
        }

        AuditLog::record($request->user(), 'cryptomus_connection.destroy', 'Removed all Cryptomus keys');

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
