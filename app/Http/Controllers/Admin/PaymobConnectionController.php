<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Payments\PaymobGateway;
use Illuminate\Http\Request;

/**
 * Paymob's keys are entered here, not .env — admin-managed, encrypted at
 * rest (platform_settings.value), same pattern as every other connection
 * screen. The three real secrets (public/secret/HMAC) never come back to
 * the browser once saved, only a masked preview each; the Integration ID
 * and the USD→EGP rate aren't secrets (an account identifier and a pricing
 * choice, not credentials), so they're returned in full.
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
            'integration_id' => $this->paymob->integrationId(),
            'usd_to_egp_rate' => $this->paymob->usdToEgpRate(),
            'configured' => $this->paymob->isConfigured(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'paymob_public_key' => ['sometimes', 'string', 'min:6'],
            'paymob_secret_key' => ['sometimes', 'string', 'min:6'],
            'paymob_hmac_secret' => ['sometimes', 'string', 'min:6'],
            'paymob_integration_id' => ['sometimes', 'string', 'min:1'],
            'paymob_usd_to_egp_rate' => ['sometimes', 'numeric', 'min:0.01'],
        ]);

        if ($data === []) {
            return response()->json(['message' => 'Nothing to save.'], 422);
        }

        foreach ($data as $key => $value) {
            PlatformSetting::set($key, (string) $value);
        }

        AuditLog::record($request->user(), 'paymob_connection.update', 'Updated Paymob key(s): '.implode(', ', array_keys($data)));

        return $this->show();
    }

    public function destroy(Request $request)
    {
        foreach (['paymob_public_key', 'paymob_secret_key', 'paymob_hmac_secret', 'paymob_integration_id', 'paymob_usd_to_egp_rate'] as $key) {
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
