<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddedSignupTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config([
            'pingly.whatsapp.app_id' => 'test-app-id',
            'pingly.whatsapp.app_secret' => 'test-app-secret',
            'pingly.whatsapp.config_id' => 'test-config-id',
            'pingly.whatsapp.access_token' => 'test-system-user-token',
            'pingly.whatsapp.api_version' => 'v21.0',
        ]);
    }

    private function clientToken(Company $company): string
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        return app(JwtService::class)->issue($user, 'user')['token'];
    }

    private function fakeMetaHappyPath(): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'short-lived-business-token']),
            '*/waba-123/subscribed_apps' => Http::response(['success' => true]),
            '*/phone-456/register' => Http::response(['success' => true]),
            '*/phone-456?*' => Http::response(['display_phone_number' => '+20 100 000 0000']),
        ]);
    }

    public function test_config_reports_unconfigured_when_the_config_id_is_missing(): void
    {
        $company = Company::factory()->create();

        $this->withToken($this->clientToken($company))
            ->getJson('/api/services/whatsapp/embedded-signup/config')
            ->assertOk()
            ->assertJson(['configured' => false]);
    }

    public function test_config_reports_configured_and_returns_the_app_id(): void
    {
        $this->configure();
        $company = Company::factory()->create();

        $this->withToken($this->clientToken($company))
            ->getJson('/api/services/whatsapp/embedded-signup/config')
            ->assertOk()
            ->assertJson(['configured' => true, 'app_id' => 'test-app-id', 'config_id' => 'test-config-id']);
    }

    public function test_connect_fails_cleanly_when_not_configured(): void
    {
        $company = Company::factory()->create();

        $response = $this->withToken($this->clientToken($company))->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'the-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-456',
        ]);

        $response->assertStatus(422)->assertJsonFragment(['message' => 'WhatsApp Embedded Signup is not configured — set META_WHATSAPP_CONFIG_ID (and the rest of META_WHATSAPP_*) first.']);
    }

    public function test_connect_requires_all_three_fields(): void
    {
        $this->configure();
        $company = Company::factory()->create();

        $this->withToken($this->clientToken($company))
            ->postJson('/api/services/whatsapp/embedded-signup', [])
            ->assertStatus(422);
    }

    /**
     * The full happy path: code exchanged, app subscribed to the WABA,
     * number registered with a generated PIN, display number fetched, and
     * the whatsapp_accounts row created connected — everything a real
     * Embedded Signup completion needs before Cloud API sending works.
     */
    public function test_connect_creates_a_connected_account_on_success(): void
    {
        $this->configure();
        $this->fakeMetaHappyPath();
        $company = Company::factory()->create();

        $response = $this->withToken($this->clientToken($company))->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'the-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-456',
        ]);

        $response->assertOk()->assertJson(['phone_number' => '+20 100 000 0000']);

        $account = WhatsappAccount::where('company_id', $company->id)->first();
        $this->assertNotNull($account);
        $this->assertSame('waba-123', $account->waba_id);
        $this->assertSame('phone-456', $account->phone_number_id);
        $this->assertSame('connected', $account->status);
        $this->assertNotNull($account->connected_at);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $account->registration_pin); // generated, not client-chosen
    }

    public function test_connect_sends_the_generated_pin_and_the_right_tokens_to_meta(): void
    {
        $this->configure();
        $this->fakeMetaHappyPath();
        $company = Company::factory()->create();

        $this->withToken($this->clientToken($company))->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'the-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-456',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/access_token')
            && $request['client_id'] === 'test-app-id'
            && $request['client_secret'] === 'test-app-secret'
            && $request['code'] === 'the-code');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/waba-123/subscribed_apps')
            && $request->hasHeader('Authorization', 'Bearer test-system-user-token'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/phone-456/register')
            && $request['messaging_product'] === 'whatsapp'
            && preg_match('/^\d{6}$/', $request['pin']) === 1);
    }

    /**
     * The registration_pin must never leak out through the JSON API —
     * it's the number's own 2FA secret, only ever used server-to-server.
     */
    public function test_registration_pin_is_never_exposed_via_the_whatsapp_endpoint(): void
    {
        $this->configure();
        $this->fakeMetaHappyPath();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);
        $token = $this->clientToken($company);

        $this->withToken($token)->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'the-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-456',
        ]);

        $response = $this->withToken($token)->getJson('/api/services/whatsapp');

        $response->assertOk();
        $this->assertArrayNotHasKey('registration_pin', $response->json('accounts.0'));
    }

    public function test_connect_fails_cleanly_when_meta_rejects_the_code_exchange(): void
    {
        $this->configure();
        Http::fake(['*/oauth/access_token*' => Http::response(['error' => 'invalid code'], 400)]);
        $company = Company::factory()->create();

        $response = $this->withToken($this->clientToken($company))->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'an-expired-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-456',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WhatsappAccount::count()); // no half-connected row left behind
    }

    public function test_connect_fails_cleanly_when_app_subscription_fails(): void
    {
        $this->configure();
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'short-lived-business-token']),
            '*/waba-123/subscribed_apps' => Http::response(['error' => 'permission denied'], 403),
        ]);
        $company = Company::factory()->create();

        $response = $this->withToken($this->clientToken($company))->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'the-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-456',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WhatsappAccount::count());
    }

    public function test_connect_fails_cleanly_when_phone_registration_fails(): void
    {
        $this->configure();
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'short-lived-business-token']),
            '*/waba-123/subscribed_apps' => Http::response(['success' => true]),
            '*/phone-456/register' => Http::response(['error' => 'already registered elsewhere'], 400),
        ]);
        $company = Company::factory()->create();

        $response = $this->withToken($this->clientToken($company))->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'the-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-456',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, WhatsappAccount::count());
    }

    /**
     * A failure fetching the display number specifically must NOT fail the
     * whole connection — it's cosmetic, everything Cloud API sending needs
     * (phone_number_id, the account being 'connected') already happened.
     */
    public function test_a_failed_display_number_lookup_does_not_block_the_connection(): void
    {
        $this->configure();
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'short-lived-business-token']),
            '*/waba-123/subscribed_apps' => Http::response(['success' => true]),
            '*/phone-456/register' => Http::response(['success' => true]),
            '*/phone-456?*' => Http::response(['error' => 'not found'], 404),
        ]);
        $company = Company::factory()->create();

        $response = $this->withToken($this->clientToken($company))->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'the-code',
            'waba_id' => 'waba-123',
            'phone_number_id' => 'phone-456',
        ]);

        $response->assertOk();
        $account = WhatsappAccount::where('company_id', $company->id)->first();
        $this->assertSame('connected', $account->status);
        $this->assertNull($account->phone_number);
    }

    /**
     * Reconnecting the same WABA (e.g. the client redoes the flow) updates
     * the existing row instead of creating a duplicate.
     */
    public function test_reconnecting_the_same_waba_updates_the_existing_row_not_a_duplicate(): void
    {
        $this->configure();
        $this->fakeMetaHappyPath();
        $company = Company::factory()->create();
        $token = $this->clientToken($company);

        $this->withToken($token)->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'first-code', 'waba_id' => 'waba-123', 'phone_number_id' => 'phone-456',
        ]);
        $this->withToken($token)->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'second-code', 'waba_id' => 'waba-123', 'phone_number_id' => 'phone-456',
        ]);

        $this->assertSame(1, WhatsappAccount::where('company_id', $company->id)->where('waba_id', 'waba-123')->count());
    }

    public function test_connect_requires_client_auth(): void
    {
        $this->postJson('/api/services/whatsapp/embedded-signup', [
            'code' => 'the-code', 'waba_id' => 'waba-123', 'phone_number_id' => 'phone-456',
        ])->assertStatus(401);
    }
}
