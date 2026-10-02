<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Auth\JwtService;
use App\Services\Billing\ServiceConfigRepository;
use App\Services\WhatsApp\WhatsAppGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Each client connects their own WhatsApp Business Account and Pingly sends
 * with their token, not a platform-wide one.
 *
 * This isn't a convenience: Meta caps an unverified business at two phone
 * numbers across all its WABAs, so every client living under one Pingly
 * account stops being a product at the second customer. These pin down that
 * a client's own token is stored, never handed back, and actually used on
 * the wire.
 */
class ClientOwnedCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAtest-system-user-token-long-enough';

    /** @return array{0: Company,1: string} */
    private function client(): array
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 50]);
        $user = User::factory()->create(['company_id' => $company->id]);

        return [$company, app(JwtService::class)->issue($user, 'user')['token']];
    }

    private function fakeMetaOk(): void
    {
        Http::fake([
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
            'graph.facebook.com/*' => Http::response([
                'display_phone_number' => '+20 100 000 0000',
                'verified_name' => 'Acme',
            ], 200),
        ]);
    }

    public function test_a_client_can_connect_their_own_credentials(): void
    {
        [$company, $token] = $this->client();
        $this->fakeMetaOk();

        $this->withToken($token)->putJson('/api/services/whatsapp/credentials', [
            'phone_number_id' => '123456789',
            'waba_id' => '987654321',
            'access_token' => self::TOKEN,
        ])->assertOk()->assertJsonPath('connected', true);

        $account = WhatsappAccount::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('123456789', $account->phone_number_id);
        $this->assertSame('connected', $account->status);
        // Picked up from Meta, so the client doesn't have to type it.
        $this->assertSame('+20 100 000 0000', $account->phone_number);
    }

    public function test_the_token_is_encrypted_at_rest_and_never_returned(): void
    {
        [$company, $token] = $this->client();
        $this->fakeMetaOk();

        $response = $this->withToken($token)->putJson('/api/services/whatsapp/credentials', [
            'phone_number_id' => '123456789',
            'waba_id' => '987654321',
            'access_token' => self::TOKEN,
        ])->assertOk();

        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());

        $raw = \DB::table('whatsapp_accounts')->where('company_id', $company->id)->value('access_token');
        $this->assertNotSame(self::TOKEN, $raw, 'token was stored in plaintext');

        // Still readable through the model's cast.
        $this->assertSame(self::TOKEN, WhatsappAccount::where('company_id', $company->id)->first()->access_token);
    }

    public function test_an_expired_token_is_rejected_before_anything_is_saved(): void
    {
        [$company, $token] = $this->client();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['code' => 190, 'message' => 'Error validating access token: Session has expired'],
            ], 401),
        ]);

        $this->withToken($token)->putJson('/api/services/whatsapp/credentials', [
            'phone_number_id' => '123456789',
            'waba_id' => '987654321',
            'access_token' => self::TOKEN,
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'System User token'));

        $this->assertDatabaseCount('whatsapp_accounts', 0);
    }

    public function test_a_wrong_phone_number_id_is_rejected_with_a_usable_message(): void
    {
        [, $token] = $this->client();

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 803]], 404)]);

        $this->withToken($token)->putJson('/api/services/whatsapp/credentials', [
            'phone_number_id' => 'not-a-real-id',
            'waba_id' => '987654321',
            'access_token' => self::TOKEN,
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Phone number ID'));

        $this->assertDatabaseCount('whatsapp_accounts', 0);
    }

    /** The point of the whole change: the wire carries the client's token. */
    public function test_sending_uses_the_clients_own_token_not_the_platform_one(): void
    {
        [$company] = $this->client();
        config(['pingly.whatsapp.access_token' => 'PLATFORM-TOKEN-SHOULD-NOT-BE-USED']);

        app(ServiceConfigRepository::class)->setPlatformDefault(ServiceType::WhatsApp, [
            'margin_percent' => 25,
            'monthly_fee' => 0,
            'base_costs' => [['category' => 'service', 'country' => 'EG', 'cost' => 0.0]],
        ]);

        WhatsappAccount::create([
            'company_id' => $company->id,
            'waba_id' => '987654321',
            'phone_number_id' => '123456789',
            'access_token' => self::TOKEN,
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $this->fakeMetaOk();

        app(WhatsAppGatewayService::class)->send(
            $company,
            '201000000000',
            'service',
            'EG',
            ['type' => 'text', 'text' => ['body' => 'hi']],
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/messages')) {
                return false;
            }

            return $request->header('Authorization')[0] === 'Bearer '.self::TOKEN;
        });
    }

    public function test_a_client_can_disconnect(): void
    {
        [$company, $token] = $this->client();
        WhatsappAccount::create([
            'company_id' => $company->id,
            'waba_id' => '1', 'phone_number_id' => '2',
            'access_token' => self::TOKEN, 'status' => 'connected', 'connected_at' => now(),
        ]);

        $this->withToken($token)->deleteJson('/api/services/whatsapp/credentials')
            ->assertOk()->assertJsonPath('connected', false);

        $this->assertDatabaseCount('whatsapp_accounts', 0);
    }
}
