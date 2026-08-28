<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\PlatformSetting;
use App\Services\Payments\PaymobGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PaymobGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        PlatformSetting::set('paymob_public_key', 'pk_test_public');
        PlatformSetting::set('paymob_secret_key', 'sk_test_secret');
        PlatformSetting::set('paymob_hmac_secret', 'test-hmac-secret');
        PlatformSetting::set('paymob_integration_id', '987654');
        PlatformSetting::set('paymob_usd_to_egp_rate', '49.5');
    }

    public function test_it_is_not_configured_until_all_five_settings_are_set(): void
    {
        $gateway = app(PaymobGateway::class);
        $this->assertFalse($gateway->isConfigured());

        PlatformSetting::set('paymob_public_key', 'pk_test_public');
        PlatformSetting::set('paymob_secret_key', 'sk_test_secret');
        PlatformSetting::set('paymob_hmac_secret', 'test-hmac-secret');
        PlatformSetting::set('paymob_integration_id', '987654');
        $this->assertFalse($gateway->isConfigured()); // usd_to_egp_rate still missing

        PlatformSetting::set('paymob_usd_to_egp_rate', '49.5');
        $this->assertTrue($gateway->isConfigured());
    }

    public function test_create_topup_session_fails_cleanly_when_not_configured(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not configured');

        app(PaymobGateway::class)->createTopupSession($company, 25, 'USD');
    }

    public function test_create_topup_session_rejects_a_non_usd_wallet(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only supports USD');

        app(PaymobGateway::class)->createTopupSession($company, 25, 'EGP');
    }

    /**
     * The core of the currency-boundary design (docs on PaymobGateway::usdToEgpRate()):
     * the wallet asks for USD, the card gets charged in EGP at the admin-set rate,
     * and the *requested* USD amount travels along in `extras` for the webhook to
     * credit back exactly — never re-derived from the EGP figure.
     */
    public function test_create_topup_session_converts_to_egp_and_returns_the_checkout_url(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        Http::fake([
            '*/v1/intention/' => Http::response(['client_secret' => 'cs_test_abc123']),
        ]);

        $url = app(PaymobGateway::class)->createTopupSession($company, 25.5, 'USD');

        $this->assertSame('https://accept.paymob.com/unifiedcheckout/?publicKey=pk_test_public&clientSecret=cs_test_abc123', $url);

        Http::assertSent(function ($request) use ($company) {
            return $request->url() === 'https://accept.paymob.com/v1/intention/'
                && $request->hasHeader('Authorization', 'Token sk_test_secret')
                && $request['amount'] === 126225 // 25.50 USD * 49.5 rate = 1262.25 EGP, in piastres
                && $request['currency'] === 'EGP'
                // Confirmed live 2026-08-28 — the string "card" is rejected
                // ("Integration ID/Name does not exist"); it has to be this
                // account's actual numeric Integration ID.
                && $request['payment_methods'] === [987654]
                && $request['extras']['company_id'] === $company->id
                && $request['extras']['requested_amount'] === 25.5
                && $request['extras']['requested_currency'] === 'USD'
                // Confirmed live 2026-08-28 — Paymob rejects the request without this,
                // despite the docs calling it optional. See billingDataFor()'s docblock.
                && filled($request['billing_data']['email'] ?? null)
                && filled($request['billing_data']['phone_number'] ?? null);
        });
    }

    public function test_create_topup_session_fails_cleanly_when_paymob_rejects_the_request(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        Http::fake(['*/v1/intention/' => Http::response(['message' => 'bad request'], 400)]);

        $this->expectException(RuntimeException::class);
        app(PaymobGateway::class)->createTopupSession($company, 25, 'USD');
    }

    /**
     * @return array{obj: array, hmac: string}
     */
    private function signedTransaction(array $overrides = []): array
    {
        $obj = array_merge([
            'amount_cents' => 126225, // the EGP charge — 1262.25 EGP
            'created_at' => '2026-08-28T12:00:00Z',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => 998877,
            'integration_id' => 987654,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => 445566],
            'owner' => 1,
            'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ], $overrides);

        $stringify = fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? '');
        $fields = [
            $obj['amount_cents'], $obj['created_at'], $obj['currency'], $obj['error_occured'],
            $obj['has_parent_transaction'], $obj['id'], $obj['integration_id'], $obj['is_3d_secure'],
            $obj['is_auth'], $obj['is_capture'], $obj['is_refunded'], $obj['is_standalone_payment'],
            $obj['is_voided'], $obj['order']['id'], $obj['owner'], $obj['pending'],
            $obj['source_data']['pan'], $obj['source_data']['sub_type'], $obj['source_data']['type'], $obj['success'],
        ];
        $hmac = hash_hmac('sha512', implode('', array_map($stringify, $fields)), 'test-hmac-secret');

        return ['obj' => $obj, 'hmac' => $hmac];
    }

    /**
     * The wallet must be credited the requested USD amount (25.50), not the
     * EGP figure amount_cents represents (1262.25) — proves the webhook
     * reads extras.requested_amount rather than re-deriving from the charge.
     */
    public function test_webhook_with_a_valid_signature_credits_the_requested_usd_amount(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        ['obj' => $obj, 'hmac' => $hmac] = $this->signedTransaction();
        $obj['extras'] = ['company_id' => $company->id, 'requested_amount' => 25.5, 'requested_currency' => 'USD']; // not part of the HMAC — see class docblock

        $response = $this->postJson("/api/webhooks/paymob?hmac={$hmac}", ['type' => 'TRANSACTION', 'obj' => $obj]);

        $response->assertOk();
        $this->assertEquals('25.5000', $company->wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_topups', ['provider' => 'paymob', 'provider_reference' => '998877', 'status' => 'completed', 'currency' => 'USD']);
    }

    public function test_webhook_credit_is_idempotent_on_the_transaction_id(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        ['obj' => $obj, 'hmac' => $hmac] = $this->signedTransaction();
        $obj['extras'] = ['company_id' => $company->id, 'requested_amount' => 25.5, 'requested_currency' => 'USD'];

        $this->postJson("/api/webhooks/paymob?hmac={$hmac}", ['type' => 'TRANSACTION', 'obj' => $obj]);
        $this->postJson("/api/webhooks/paymob?hmac={$hmac}", ['type' => 'TRANSACTION', 'obj' => $obj]); // retry

        $this->assertEquals('25.5000', $company->wallet->fresh()->balance); // not double-credited
    }

    public function test_webhook_with_an_invalid_signature_is_rejected(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        ['obj' => $obj] = $this->signedTransaction();
        $obj['extras'] = ['company_id' => $company->id, 'requested_amount' => 25.5, 'requested_currency' => 'USD'];

        $response = $this->postJson('/api/webhooks/paymob?hmac=not-the-real-hmac', ['type' => 'TRANSACTION', 'obj' => $obj]);

        $response->assertStatus(400);
        $this->assertEquals('0.0000', $company->wallet->fresh()->balance);
    }

    public function test_a_still_pending_transaction_does_not_credit_the_wallet(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        ['obj' => $obj, 'hmac' => $hmac] = $this->signedTransaction(['pending' => true]);
        $obj['extras'] = ['company_id' => $company->id, 'requested_amount' => 25.5, 'requested_currency' => 'USD'];

        $response = $this->postJson("/api/webhooks/paymob?hmac={$hmac}", ['type' => 'TRANSACTION', 'obj' => $obj]);

        $response->assertOk(); // acknowledged, just not credited yet
        $this->assertEquals('0.0000', $company->wallet->fresh()->balance);
    }
}
