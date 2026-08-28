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
 * community SDK before writing this, then against a real test-mode charge
 * (2026-08-28) — that live attempt is what caught `billing_data` actually
 * being required (the docs call it optional; it isn't — see
 * billingDataFor()). Everything else — the request/response shape, the
 * `/unifiedcheckout` URL format, the HMAC field list — matched on the
 * first try. The one thing still genuinely unconfirmed: the exact string
 * form Paymob uses for a boolean in the HMAC concatenation (see
 * verifySignature()) — that only surfaces once a real webhook fires.
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

    /**
     * Confirmed live (2026-08-28): `payment_methods: ['card']` (the string
     * form the docs describe) is rejected with "Integration ID/Name does
     * not exist in our system" — this account's actual numeric Integration
     * ID (dashboard → Developers → Payment Integrations) is what's needed
     * instead. That ID is account-specific, so it's admin-entered like the
     * other three keys, not hardcoded.
     */
    public function integrationId(): ?string
    {
        return PlatformSetting::get('paymob_integration_id') ?: config('services.paymob.integration_id');
    }

    /**
     * The Integration ID above (5885111 on the account this was built
     * against) is fixed to EGP — confirmed live (2026-08-28), Paymob
     * rejects any other `currency` value on the create-intention call. But
     * the wallet itself is USD (see Wallet.currency / every UsageEvent /
     * the whole billing engine) — converting the *wallet ledger* to EGP
     * would mean touching BillingEngine and every usage calculation, a much
     * bigger change than this gateway needs. So the conversion happens only
     * here, at the charge boundary: the card gets charged in EGP at this
     * rate, but the wallet is credited the exact USD amount the client
     * actually asked for (stored in `extras`, not re-derived from the EGP
     * figure that comes back — see handleWebhook()). Admin-set, not fetched
     * live, so it never silently drifts without someone choosing a value —
     * update it here whenever the real rate moves meaningfully.
     */
    public function usdToEgpRate(): ?float
    {
        $rate = PlatformSetting::get('paymob_usd_to_egp_rate') ?: config('services.paymob.usd_to_egp_rate');

        return $rate ? (float) $rate : null;
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey()) && filled($this->publicKey())
            && filled($this->hmacSecret()) && filled($this->integrationId())
            && $this->usdToEgpRate() > 0;
    }

    public function createTopupSession(Company $company, float $amount, string $currency): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Paymob is not configured — add the API keys (and the USD→EGP rate) in the admin dashboard first.');
        }

        if (strtoupper($currency) !== 'USD') {
            // Nothing in this app creates a non-USD wallet today — this is a
            // guard against silently mis-converting if that ever changes,
            // not a real code path.
            throw new RuntimeException("Paymob gateway only supports USD wallets today (got {$currency}).");
        }

        $egpAmount = round($amount * $this->usdToEgpRate(), 2);
        $base = rtrim(config('pingly.paymob.api_base'), '/');

        try {
            $response = Http::withToken($this->secretKey(), 'Token')
                ->timeout(30)
                ->post("{$base}/v1/intention/", [
                    'amount' => (int) round($egpAmount * 100), // Paymob wants the smallest currency unit
                    'currency' => 'EGP', // fixed by the Integration ID, not by the caller — see usdToEgpRate()
                    'payment_methods' => [(int) $this->integrationId()],
                    'billing_data' => $this->billingDataFor($company),
                    'special_reference' => (string) $company->id.'-'.now()->timestamp,
                    'extras' => [
                        'company_id' => $company->id,
                        // The wallet gets credited this exact figure on success — never
                        // re-derived from amount_cents/currency in the webhook, so a
                        // stale rate or EGP rounding never changes what the client's
                        // wallet actually receives versus what they were shown.
                        'requested_amount' => $amount,
                        'requested_currency' => 'USD',
                    ],
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

    /**
     * Confirmed live (2026-08-28): despite the docs calling `billing_data`
     * optional, Paymob rejects a create-intention request without it —
     * `first_name`/`last_name`/`email`/`phone_number` specifically. Company
     * doesn't collect a phone number or a real postal address today, so
     * those go in as clearly-fake placeholders; Paymob doesn't validate
     * their authenticity for a card payment, only that the fields exist.
     * If that ever changes, this is the one place to add real fields to.
     *
     * @return array<string, string>
     */
    private function billingDataFor(Company $company): array
    {
        [$firstName, $lastName] = array_pad(explode(' ', $company->name, 2), 2, 'Account');

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $company->contact_email,
            'phone_number' => '+201000000000', // placeholder — not collected from the company today
            'street' => 'NA',
            'building' => 'NA',
            'floor' => 'NA',
            'apartment' => 'NA',
            'city' => 'NA',
            'state' => 'NA',
            'country' => 'NA',
            'postal_code' => 'NA',
            'shipping_method' => 'NA',
        ];
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
            $extras = $obj['extras'] ?? [];
            $companyId = $extras['company_id'] ?? null;
            $company = $companyId ? Company::find($companyId) : null;

            if ($company) {
                // Credit the USD amount the client actually requested (see
                // createTopupSession()'s docblock) — not amount_cents/currency,
                // which is the EGP figure the card was actually charged.
                $this->billing->creditTopup(
                    company: $company,
                    amount: (float) ($extras['requested_amount'] ?? ((int) ($obj['amount_cents'] ?? 0)) / 100),
                    currency: strtoupper($extras['requested_currency'] ?? $obj['currency'] ?? 'EGP'),
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
