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
 * Paymob (paymob.com) — Egyptian fintech, licensed by the Central Bank of
 * Egypt, also live in Saudi/UAE/Oman. Picked as the merchant account we
 * could actually open today (Egyptian ID + IBAN, no commercial register
 * required at this volume) — see the account's own dashboard for the
 * onboarding status.
 *
 * Uses the "Intention API" — a single POST creates a payment intention and
 * returns a `client_secret`, which combines with the account's public key
 * to build a Unified Checkout URL (a Paymob-hosted page; card details never
 * touch this backend). The wallet is only ever credited from the webhook
 * once Paymob confirms `success: true`, never from the redirect back to
 * the browser alone — same rule as Stripe/Tap.
 *
 * Verified against Paymob's public developer docs and an independent
 * community SDK (not a real sandbox charge — that needs the merchant
 * account to finish review first): the request/response shape, the
 * `/unifiedcheckout` URL format, and the HMAC field list all matched
 * across both sources, which is more corroboration than Tap's integration
 * had. Still worth confirming against the first real test charge —
 * particularly whether `billing_data` turns out to be required despite the
 * docs calling it optional, and the exact string form Paymob uses for a
 * boolean in the HMAC concatenation (see verifySignature()).
 */
class PaymobGateway implements PaymentGateway
{
    public function __construct(private readonly BillingEngine $billing) {}

    public function secretKey(): ?string
    {
        return PlatformSetting::get('paymob_secret_key') ?: config('services.paymob.secret_key');
    }

    public function publicKey(): ?string
    {
        return PlatformSetting::get('paymob_public_key') ?: config('services.paymob.public_key');
    }

    public function hmacSecret(): ?string
    {
        return PlatformSetting::get('paymob_hmac_secret') ?: config('services.paymob.hmac_secret');
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey()) && filled($this->publicKey()) && filled($this->hmacSecret());
    }

    public function createTopupSession(Company $company, float $amount, string $currency): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Paymob is not configured — add the API keys in the admin dashboard first.');
        }

        $base = rtrim(config('pingly.paymob.api_base'), '/');

        try {
            $response = Http::withToken($this->secretKey(), 'Token')
                ->timeout(30)
                ->post("{$base}/v1/intention/", [
                    'amount' => (int) round($amount * 100), // Paymob wants the smallest currency unit
                    'currency' => strtoupper($currency),
                    'payment_methods' => ['card'],
                    'special_reference' => (string) $company->id.'-'.now()->timestamp,
                    'extras' => ['company_id' => $company->id], // echoed back in the webhook — see handleWebhook()
                    'notification_url' => config('app.url').'/api/webhooks/paymob',
                    'redirection_url' => config('pingly.frontend_url').'/wallet?topup=success',
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Couldn't reach Paymob: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new RuntimeException('Paymob request failed: '.$response->body());
        }

        $clientSecret = $response->json('client_secret');

        if (! $clientSecret) {
            throw new RuntimeException('Paymob did not return a client_secret: '.$response->body());
        }

        return "{$base}/unifiedcheckout/?publicKey={$this->publicKey()}&clientSecret={$clientSecret}";
    }

    public function handleWebhook(Request $request): Response
    {
        // HMAC arrives as a query param on the callback URL, not a header —
        // confirmed independently of the prose docs via a working community
        // integration (see class docblock).
        $hmac = (string) $request->query('hmac', '');
        $payload = $request->json()->all();
        $obj = $payload['obj'] ?? $payload; // some Paymob callback shapes send the transaction unwrapped

        if (! $this->verifySignature($obj, $hmac)) {
            Log::warning('Paymob webhook HMAC verification failed', ['id' => $obj['id'] ?? null]);

            return response('Invalid signature', 400);
        }

        $success = ($obj['success'] ?? false) === true;
        $pending = ($obj['pending'] ?? true) === true;

        if ($success && ! $pending) {
            $companyId = $obj['extras']['company_id'] ?? null;
            $company = $companyId ? Company::find($companyId) : null;

            if ($company) {
                $this->billing->creditTopup(
                    company: $company,
                    amount: ((int) ($obj['amount_cents'] ?? 0)) / 100,
                    currency: strtoupper($obj['currency'] ?? 'EGP'),
                    provider: 'paymob',
                    providerReference: (string) ($obj['id'] ?? $obj['order']['id'] ?? ''),
                );
            } else {
                Log::warning('Paymob successful-transaction webhook with no resolvable company', ['id' => $obj['id'] ?? null]);
            }
        }

        return response('ok', 200);
    }

    /**
     * HMAC-SHA512 over a fixed, documented field order (developers.paymob.com
     * → Webhooks & HMAC → Transaction Processed Callback), keyed with the
     * HMAC secret from the dashboard's Developers → API Keys screen. Hex,
     * lowercase.
     *
     * The one thing not nailed down by the docs: the exact string form of a
     * boolean field once concatenated. This uses "true"/"false" (the most
     * common convention in the third-party implementations checked while
     * building this) — if the very first real webhook gets rejected here,
     * this line is the first thing to try flipping to "1"/"" instead.
     */
    private function verifySignature(array $obj, string $hmac): bool
    {
        if (blank($hmac) || blank($this->hmacSecret())) {
            return false;
        }

        $stringify = fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? '');

        $fields = [
            $obj['amount_cents'] ?? null,
            $obj['created_at'] ?? null,
            $obj['currency'] ?? null,
            $obj['error_occured'] ?? null,
            $obj['has_parent_transaction'] ?? null,
            $obj['id'] ?? null,
            $obj['integration_id'] ?? null,
            $obj['is_3d_secure'] ?? null,
            $obj['is_auth'] ?? null,
            $obj['is_capture'] ?? null,
            $obj['is_refunded'] ?? null,
            $obj['is_standalone_payment'] ?? null,
            $obj['is_voided'] ?? null,
            $obj['order']['id'] ?? null,
            $obj['owner'] ?? null,
            $obj['pending'] ?? null,
            $obj['source_data']['pan'] ?? null,
            $obj['source_data']['sub_type'] ?? null,
            $obj['source_data']['type'] ?? null,
            $obj['success'] ?? null,
        ];

        $toHash = implode('', array_map($stringify, $fields));
        $expected = hash_hmac('sha512', $toHash, $this->hmacSecret());

        return hash_equals($expected, $hmac);
    }
}
