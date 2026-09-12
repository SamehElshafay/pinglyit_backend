<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Services\Billing\BillingEngine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Tap Payments (tap.company) — cards + Mada across Saudi, UAE, and the rest
 * of the GCC through one integration (docs §4.7, decided: most revenue is
 * Gulf-based). Same hosted-checkout shape as StripeGateway: create a charge
 * with source `src_all` (Tap's "show every payment method on our hosted
 * page" token), send the client to the URL it returns, credit the wallet
 * only once the webhook confirms `status: CAPTURED`.
 *
 * Verified against Tap's public API docs (developers.tap.company), not
 * against a real sandbox account — there wasn't one to test with while
 * building this. The request/response shape and the webhook hashstring
 * algorithm below should be double-checked against Tap's own dashboard
 * docs the first time a real test charge is attempted; if the hashstring
 * check ever rejects a genuine webhook, that field order is the first
 * place to look (see handleWebhook()).
 */
class TapGateway implements PaymentGateway
{
    public function __construct(private readonly BillingEngine $billing) {}

    /**
     * The admin enters this from the dashboard (Billing → Payment gateway) —
     * it lives encrypted in platform_settings, not .env. TAP_SECRET_KEY
     * still works as a fallback if someone prefers env-based config, but
     * the DB value always wins when both are set (same pattern as the
     * OpenRouter key — see AiGatewayService::apiKey()).
     */
    public function secretKey(): ?string
    {
        return PlatformSetting::get('tap_secret_key') ?: config('services.tap.secret_key');
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey());
    }

    public function createTopupSession(Company $company, float $amount, string $currency): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Tap Payments is not configured — add the API key in the admin dashboard first.');
        }

        try {
            $response = Http::withToken($this->secretKey())
                ->timeout(30)
                ->post('https://api.tap.company/v2/charges', [
                    'amount' => round($amount, 2),
                    'currency' => strtoupper($currency),
                    'customer' => [
                        'first_name' => $company->name,
                        'email' => $company->contact_email,
                    ],
                    'source' => ['id' => 'src_all'], // Tap's hosted page showing every available method (cards, Mada, wallets)
                    'redirect' => ['url' => rtrim(config('pingly.frontend_url'), '/').'/wallet?topup=success'],
                    'post' => ['url' => rtrim(config('app.url'), '/').'/api/webhooks/tap'],
                    'reference' => ['transaction' => (string) $company->id.'-'.now()->timestamp],
                    'metadata' => ['company_id' => $company->id],
                    'description' => 'Pingly wallet top-up',
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Couldn't reach Tap: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new RuntimeException('Tap request failed: '.$response->body());
        }

        $url = $response->json('transaction.url');

        if (! $url) {
            throw new RuntimeException('Tap did not return a checkout URL: '.$response->body());
        }

        return $url;
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->json()->all();
        $hashstring = $request->header('hashstring', '');

        if (! $this->verifySignature($payload, $hashstring)) {
            Log::warning('Tap webhook hashstring verification failed', ['id' => $payload['id'] ?? null]);

            return response('Invalid signature', 400);
        }

        if (($payload['status'] ?? null) === 'CAPTURED') {
            $companyId = $payload['metadata']['company_id'] ?? null;
            $company = $companyId ? Company::find($companyId) : null;

            if ($company) {
                $this->billing->creditTopup(
                    company: $company,
                    amount: (float) ($payload['amount'] ?? 0),
                    currency: strtoupper($payload['currency'] ?? 'USD'),
                    provider: 'tap',
                    providerReference: $payload['id'],
                );
            } else {
                Log::warning('Tap CAPTURED webhook with no resolvable company', ['id' => $payload['id'] ?? null]);
            }
        }

        return response('ok', 200);
    }

    /**
     * HMAC-SHA256 over a fixed field order, keyed with the secret API key
     * (not a separate webhook secret — Tap doesn't issue one). See the
     * class docblock: this exact field order is the one thing here that
     * couldn't be verified against a real account.
     */
    private function verifySignature(array $payload, string $hashstring): bool
    {
        if (blank($hashstring) || blank($this->secretKey())) {
            return false;
        }

        $toHash = 'x_id'.($payload['id'] ?? '')
            .'x_amount'.($payload['amount'] ?? '')
            .'x_currency'.($payload['currency'] ?? '')
            .'x_gateway_reference'.($payload['reference']['gateway'] ?? '')
            .'x_payment_reference'.($payload['reference']['payment'] ?? '')
            .'x_status'.($payload['status'] ?? '')
            .'x_created'.($payload['transaction']['created'] ?? '');

        $expected = hash_hmac('sha256', $toHash, $this->secretKey());

        return hash_equals($expected, $hashstring);
    }
}
