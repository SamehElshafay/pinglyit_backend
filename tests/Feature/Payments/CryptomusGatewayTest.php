<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\WalletTopup;
use App\Services\Billing\BillingEngine;
use App\Services\Payments\CryptomusGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CryptomusGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-payment-api-key';

    private function configure(): void
    {
        PlatformSetting::set('cryptomus_merchant_id', 'merchant-uuid-1234');
        PlatformSetting::set('cryptomus_api_key', self::API_KEY);
    }

    public function test_it_is_not_configured_until_both_settings_are_set(): void
    {
        $gateway = app(CryptomusGateway::class);
        $this->assertFalse($gateway->isConfigured());

        PlatformSetting::set('cryptomus_merchant_id', 'merchant-uuid-1234');
        $this->assertFalse($gateway->isConfigured()); // api key still missing

        PlatformSetting::set('cryptomus_api_key', self::API_KEY);
        $this->assertTrue($gateway->isConfigured());
    }

    public function test_create_topup_session_fails_cleanly_when_not_configured(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not configured');

        app(CryptomusGateway::class)->createTopupSession($company, 25, 'USD');
    }

    public function test_create_topup_session_rejects_a_non_usd_wallet(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only supports USD');

        app(CryptomusGateway::class)->createTopupSession($company, 25, 'EGP');
    }

    /**
     * Confirms the request is both correctly signed AND sent as the exact
     * same bytes that were signed (the gateway uses withBody(), not
     * Laravel's array-based ->post(), precisely so these can never drift
     * apart from each other) — and that the USD amount is recorded as a
     * *pending* WalletTopup before the customer ever reaches checkout.
     */
    public function test_create_topup_session_signs_the_request_and_records_a_pending_usd_topup(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        Http::fake([
            '*/v1/payment' => Http::response(['state' => 0, 'result' => ['uuid' => 'inv-uuid-1', 'url' => 'https://pay.cryptomus.com/pay/inv-uuid-1']]),
        ]);

        $url = app(CryptomusGateway::class)->createTopupSession($company, 25.5, 'USD');

        $this->assertSame('https://pay.cryptomus.com/pay/inv-uuid-1', $url);

        Http::assertSent(function ($request) use ($company) {
            $body = (string) $request->body();
            $expectedSign = md5(base64_encode($body).self::API_KEY);

            return $request->url() === 'https://api.cryptomus.com/v1/payment'
                && $request->hasHeader('merchant', 'merchant-uuid-1234')
                && $request->hasHeader('sign', $expectedSign) // proves signing matches what was actually sent, byte for byte
                && $request['amount'] === '25.50'
                && $request['currency'] === 'USD'
                && $request['is_payment_multiple'] === false // an underpayment must not count as "paid"
                && str_starts_with($request['order_id'], "{$company->id}-");
        });

        $topup = WalletTopup::where('provider', 'cryptomus')->first();
        $this->assertNotNull($topup);
        $this->assertSame($company->id, $topup->company_id);
        $this->assertEquals('25.5000', $topup->amount);
        $this->assertSame('USD', $topup->currency);
        $this->assertSame('pending', $topup->status);
    }

    public function test_create_topup_session_fails_cleanly_when_cryptomus_rejects_the_request(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        Http::fake(['*/v1/payment' => Http::response(['state' => 1, 'message' => 'bad request'], 422)]);

        $this->expectException(RuntimeException::class);
        app(CryptomusGateway::class)->createTopupSession($company, 25, 'USD');
    }

    public function test_create_topup_session_fails_cleanly_on_a_non_zero_state_even_with_a_200(): void
    {
        // Cryptomus, like several providers, can answer HTTP 200 with an
        // application-level error — state must be checked independently
        // of the HTTP status code.
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        Http::fake(['*/v1/payment' => Http::response(['state' => 1, 'message' => 'validation error'], 200)]);

        $this->expectException(RuntimeException::class);
        app(CryptomusGateway::class)->createTopupSession($company, 25, 'USD');
    }

    /**
     * @return array{data: array, sign: string}
     */
    private function signedWebhookPayload(array $overrides = []): array
    {
        $data = array_merge([
            'type' => 'payment',
            'uuid' => 'inv-uuid-1',
            'order_id' => 'placeholder', // overwritten per-test with the real reference
            'amount' => '25.50',
            'payment_amount' => '25.50',
            'merchant_amount' => '25.25',
            'currency' => 'USD',
            'network' => 'tron',
            'txid' => 'abc123',
            'status' => 'paid',
        ], $overrides);

        $sign = md5(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)).self::API_KEY);

        return ['data' => $data, 'sign' => $sign];
    }

    public function test_webhook_credits_the_pre_recorded_amount_on_a_paid_status(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);
        app(BillingEngine::class)->recordPendingTopup($company, 25.5, 'USD', 'cryptomus', "{$company->id}-1234567890");

        ['data' => $data, 'sign' => $sign] = $this->signedWebhookPayload(['order_id' => "{$company->id}-1234567890"]);

        $response = $this->postJson('/api/webhooks/cryptomus', [...$data, 'sign' => $sign]);

        $response->assertOk();
        $this->assertEquals('25.5000', $company->wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_topups', [
            'provider' => 'cryptomus',
            'provider_reference' => "{$company->id}-1234567890",
            'status' => 'completed',
            'amount' => '25.5000',
            'currency' => 'USD',
        ]);
    }

    public function test_webhook_credits_on_paid_over_too(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);
        app(BillingEngine::class)->recordPendingTopup($company, 25.5, 'USD', 'cryptomus', "{$company->id}-1234567890");

        ['data' => $data, 'sign' => $sign] = $this->signedWebhookPayload(['order_id' => "{$company->id}-1234567890", 'status' => 'paid_over']);

        $this->postJson('/api/webhooks/cryptomus', [...$data, 'sign' => $sign])->assertOk();

        // The pre-recorded USD amount, not whatever extra the customer sent.
        $this->assertEquals('25.5000', $company->wallet->fresh()->balance);
    }

    public function test_webhook_credit_is_idempotent_on_the_reference(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);
        app(BillingEngine::class)->recordPendingTopup($company, 25.5, 'USD', 'cryptomus', "{$company->id}-1234567890");

        ['data' => $data, 'sign' => $sign] = $this->signedWebhookPayload(['order_id' => "{$company->id}-1234567890"]);

        $this->postJson('/api/webhooks/cryptomus', [...$data, 'sign' => $sign]);
        $this->postJson('/api/webhooks/cryptomus', [...$data, 'sign' => $sign]); // retry

        $this->assertEquals('25.5000', $company->wallet->fresh()->balance); // not double-credited
    }

    public function test_webhook_with_an_invalid_signature_is_rejected(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);
        app(BillingEngine::class)->recordPendingTopup($company, 25.5, 'USD', 'cryptomus', "{$company->id}-1234567890");

        ['data' => $data] = $this->signedWebhookPayload(['order_id' => "{$company->id}-1234567890"]);

        $response = $this->postJson('/api/webhooks/cryptomus', [...$data, 'sign' => 'not-the-real-signature']);

        $response->assertStatus(400);
        $this->assertEquals('0.0000', $company->wallet->fresh()->balance);
    }

    public function test_a_non_paid_status_does_not_credit_the_wallet(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);
        app(BillingEngine::class)->recordPendingTopup($company, 25.5, 'USD', 'cryptomus', "{$company->id}-1234567890");

        ['data' => $data, 'sign' => $sign] = $this->signedWebhookPayload(['order_id' => "{$company->id}-1234567890", 'status' => 'confirm_check']);

        $response = $this->postJson('/api/webhooks/cryptomus', [...$data, 'sign' => $sign]);

        $response->assertOk(); // acknowledged, just not credited yet
        $this->assertEquals('0.0000', $company->wallet->fresh()->balance);
    }

    public function test_webhook_with_no_matching_pending_topup_credits_nothing(): void
    {
        $this->configure();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);
        // deliberately no recordPendingTopup() call

        ['data' => $data, 'sign' => $sign] = $this->signedWebhookPayload(['order_id' => "{$company->id}-nonexistent"]);

        $response = $this->postJson('/api/webhooks/cryptomus', [...$data, 'sign' => $sign]);

        $response->assertOk(); // acknowledged so Cryptomus doesn't retry forever
        $this->assertEquals('0.0000', $company->wallet->fresh()->balance);
    }
}
