<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\WalletTopup;
use App\Services\Billing\BillingEngine;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cryptomus (cryptomus.com) — the active gateway. Accepts USDT and other
 * crypto at a hosted checkout page, and needs no bank account or commercial
 * register to sign up (a KYC'd merchant account + domain confirmation is
 * the only onboarding step), which is what makes it reachable when Stripe's
 * and Tap's onboarding aren't.
 *
 * No currency conversion happens anywhere: Cryptomus takes the invoice in
 * USD directly (`currency: 'USD'`) and converts to whatever crypto the
 * customer pays with on its own hosted page, so the wallet (USD) and the
 * charge are the same number end to end. The wallet is only ever credited
 * from the webhook once Cryptomus confirms `status: paid`/`paid_over`,
 * never from the redirect back to the browser alone, and the *amount*
 * credited always comes from the pending WalletTopup recorded here before
 * the customer ever sees the checkout page — the webhook's own reported
 * amount is never trusted, on general principle.
 *
 * Built from Cryptomus's public API docs (doc.cryptomus.com) — not
 * verified against a real live charge (no funded merchant account existed
 * while building this), so the first real payment attempt is the actual
 * test. The one documented gotcha most likely to bite: the webhook
 * signature is computed over `json_encode($data, JSON_UNESCAPED_UNICODE)`
 * of the payload with `sign` removed — PHP's default slash-escaping in
 * json_encode differs from other languages' JSON encoders, and Cryptomus's
 * own docs call this out explicitly as a common cause of "valid webhook,
 * signature mismatch" bugs (see verifySignature()). `is_payment_multiple`
 * is deliberately sent as `false` — the default is `true` (accepts a
 * partial payment as valid), which is the opposite of what a top-up for
 * an exact USD amount needs.
 */
class CryptomusGateway implements PaymentGateway
{
    private const API_BASE = 'https://api.cryptomus.com/v1';

    public function __construct(private readonly BillingEngine $billing) {}

    /**
     * The merchant UUID (Cryptomus dashboard → Settings) — not a secret,
     * just an account identifier, but admin-managed the same way as
     * everything else here rather than hardcoded.
     */
    public function merchantId(): ?string
    {
        return PlatformSetting::get('cryptomus_merchant_id') ?: config('services.cryptomus.merchant_id');
    }

    /**
     * The *Payment* API key specifically (Cryptomus dashboard → your
     * project → API — there's a separate Payout key for withdrawals,
     * which this integration never touches). Used both to sign outgoing
     * requests and to verify incoming webhooks — Cryptomus's docs confirm
     * it's the same key for both directions.
     */
    public function apiKey(): ?string
    {
        return PlatformSetting::get('cryptomus_api_key') ?: config('services.cryptomus.api_key');
    }

    public function isConfigured(): bool
    {
        return filled($this->merchantId()) && filled($this->apiKey());
    }

    public function createTopupSession(Company $company, float $amount, string $currency): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cryptomus is not configured — add the Merchant ID and API key in the admin dashboard first.');
        }

        if (strtoupper($currency) !== 'USD') {
            // Nothing in this app creates a non-USD wallet today — this is a
            // guard against silently mis-charging if that ever changes, not
            // a real code path.
            throw new RuntimeException("Cryptomus gateway only supports USD wallets today (got {$currency}).");
        }

        // Ours, not Cryptomus's — this is the order_id handleWebhook() looks
        // the pending top-up back up by. Same shape as TapGateway's
        // reference.transaction.
        $reference = (string) $company->id.'-'.now()->timestamp;

        $body = [
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'USD',
            'order_id' => $reference,
            'url_callback' => config('app.url').'/api/webhooks/cryptomus',
            'url_return' => rtrim(config('pingly.frontend_url'), '/').'/wallet?topup=success',
            // Reject an underpayment rather than silently accept it as
            // "paid" — see the class docblock.
            'is_payment_multiple' => false,
            // Cryptomus's documented maximum (12h), not the 1h default.
            // Paying by card goes through an on-ramp that runs its own
            // identity check on the payer, which can easily outlast an
            // hour — a window that expires mid-payment is a top-up the
            // client has to start over. A crypto transfer is unaffected by
            // the longer window; it just doesn't need it.
            'lifetime' => 43200,
        ];

        // Signed and sent as the exact same bytes (via withBody, not
        // Laravel's ->post($url, $array) which would re-encode the array
        // separately) — the signature has to match what Cryptomus's server
        // recomputes from the raw body it actually received.
        $json = json_encode($body);

        try {
            $response = Http::withHeaders(['merchant' => $this->merchantId(), 'sign' => $this->sign($json)])
                ->timeout(30)
                ->withBody($json, 'application/json')
                ->post(self::API_BASE.'/payment');
        } catch (ConnectionException $e) {
            throw new RuntimeException("Couldn't reach Cryptomus: {$e->getMessage()}");
        }

        if ($response->failed() || (int) $response->json('state') !== 0) {
            throw new RuntimeException('Cryptomus request failed: '.$response->body());
        }

        $url = $response->json('result.url');

        if (! $url) {
            throw new RuntimeException('Cryptomus did not return a checkout URL: '.$response->body());
        }

        // Recorded *before* the customer even sees the checkout page — the
        // amount the wallet gets credited is decided here, by Pingly, once,
        // and never touched again regardless of what the webhook later
        // reports (see the class docblock for why).
        $this->billing->recordPendingTopup($company, $amount, 'USD', 'cryptomus', $reference);

        return $url;
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->json()->all();
        $sign = (string) ($payload['sign'] ?? '');
        $data = $payload;
        unset($data['sign']);

        if (! $this->verifySignature($data, $sign)) {
            Log::warning('Cryptomus webhook signature verification failed', ['uuid' => $payload['uuid'] ?? null]);

            return response('Invalid signature', 400);
        }

        // 'paid' — exact amount confirmed; 'paid_over' — customer sent more
        // than asked (still a fully successful top-up). Everything else
        // (confirm_check/process — still pending; wrong_amount/fail/cancel —
        // not resolved into money) is acknowledged but not credited.
        if (in_array($payload['status'] ?? null, ['paid', 'paid_over'], true)) {
            $reference = $payload['order_id'] ?? null;
            $topup = $reference
                ? WalletTopup::where('provider', 'cryptomus')->where('provider_reference', $reference)->first()
                : null;

            if ($topup) {
                $this->billing->creditTopup(
                    company: $topup->company,
                    amount: (float) $topup->amount,
                    currency: $topup->currency,
                    provider: 'cryptomus',
                    providerReference: $reference,
                );
            } else {
                Log::warning('Cryptomus paid webhook with no matching pending top-up', [
                    'order_id' => $reference,
                    'payload' => $payload,
                ]);
            }
        }

        return response('ok', 200);
    }

    private function sign(string $json): string
    {
        return md5(base64_encode($json).$this->apiKey());
    }

    /**
     * MD5(base64(json_encode($data, JSON_UNESCAPED_UNICODE)) + api key) —
     * documented explicitly by Cryptomus, `$data` being the full webhook
     * payload with `sign` itself removed. JSON_UNESCAPED_UNICODE matters:
     * Cryptomus's own PHP example uses it, and a mismatched encoding here
     * (default json_encode escapes non-ASCII to \uXXXX) silently produces
     * the wrong hash for any payload containing non-ASCII bytes.
     */
    private function verifySignature(array $data, string $sign): bool
    {
        if (blank($sign) || blank($this->apiKey())) {
            return false;
        }

        $expected = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)).$this->apiKey());

        return hash_equals($expected, $sign);
    }
}
