<?php

namespace Tests\Feature\Meta;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\MetaConnection;
use App\Models\PlatformSetting;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaAdsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAG-system-user-token-0123456789abcdefghijklmnopqrstuvwxyz';

    private Company $company;

    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->company = Company::factory()->create();
        $this->company->wallet()->create(['balance' => 50]);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->jwt = app(JwtService::class)->issue($user, 'user')['token'];
    }

    private function fakeMeta(array $permissions = ['ads_read', 'pages_show_list', 'ads_management']): void
    {
        Http::fake([
            'graph.facebook.com/*/me/permissions*' => Http::response(['data' => array_map(fn ($p) => ['permission' => $p, 'status' => 'granted'], $permissions)]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_111', 'name' => 'Bon El Sabah', 'currency' => 'EGP', 'account_status' => 1]]]),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [['id' => '222', 'name' => 'Bon El Sabah Page', 'instagram_business_account' => ['id' => '333', 'username' => 'bonelsabah']]]]),
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['expires_at' => 0]]),
            'graph.facebook.com/*/me?*' => Http::response(['id' => '999', 'name' => 'Pingly System User']),
            'graph.facebook.com/*/act_111/campaigns*' => Http::response(['data' => [
                ['id' => 'c1', 'name' => 'Ramadan Sales', 'objective' => 'OUTCOME_SALES', 'effective_status' => 'ACTIVE', 'daily_budget' => '50000'],
                ['id' => 'c2', 'name' => 'Brand Reach', 'objective' => 'OUTCOME_AWARENESS', 'effective_status' => 'PAUSED'],
            ]]),
            'graph.facebook.com/*/act_111/insights*' => function ($request) {
                if (isset($request->data()['time_increment'])) {
                    return Http::response(['data' => [['date_start' => '2026-10-30', 'spend' => '100', 'impressions' => '1000', 'clicks' => '20']]]);
                }

                return Http::response(['data' => [
                    ['campaign_id' => 'c1', 'spend' => '4000', 'impressions' => '200000', 'reach' => '90000', 'clicks' => '3000', 'ctr' => '1.5', 'cpc' => '1.33', 'cpm' => '20',
                        'actions' => [['action_type' => 'link_click', 'value' => '2500'], ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '40']]],
                    ['campaign_id' => 'c2', 'spend' => '1000', 'impressions' => '150000', 'reach' => '120000', 'clicks' => '300'],
                ]]);
            },
        ]);
    }

    private function connect(): void
    {
        $this->withToken($this->jwt)->postJson('/api/meta/connect', [
            'access_token' => self::TOKEN, 'ad_account_id' => 'act_111', 'page_id' => '222',
        ])->assertOk()->assertJsonPath('connection.instagram_username', 'bonelsabah');
    }

    public function test_a_token_is_inspected_then_saved_encrypted_and_never_returned(): void
    {
        $this->fakeMeta();

        $this->withToken($this->jwt)->postJson('/api/meta/inspect', ['access_token' => self::TOKEN])
            ->assertOk()
            ->assertJsonPath('ad_accounts.0.id', 'act_111')
            ->assertJsonPath('missing_required', []);

        $this->connect();

        $raw = \DB::table('meta_connections')->value('access_token');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, MetaConnection::first()->access_token);
        $this->assertNull(MetaConnection::first()->token_expires_at); // System User token: never expires

        $this->assertStringNotContainsString(self::TOKEN, $this->withToken($this->jwt)->getJson('/api/meta')->getContent());
    }

    public function test_an_ad_account_the_token_cant_see_is_refused(): void
    {
        $this->fakeMeta();

        $this->withToken($this->jwt)->postJson('/api/meta/connect', [
            'access_token' => self::TOKEN, 'ad_account_id' => 'act_999',
        ])->assertStatus(422);

        $this->assertSame(0, MetaConnection::count());
    }

    public function test_a_token_without_read_permission_is_refused(): void
    {
        $this->fakeMeta(permissions: ['pages_show_list']);

        $this->withToken($this->jwt)->postJson('/api/meta/connect', [
            'access_token' => self::TOKEN, 'ad_account_id' => 'act_111',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'This token is missing: ads_read. Regenerate it with those permissions ticked.']);
    }

    public function test_campaigns_come_back_with_their_real_result_per_objective(): void
    {
        $this->fakeMeta();
        $this->connect();

        $response = $this->withToken($this->jwt)->getJson('/api/meta/campaigns?period=last_30d')->assertOk();

        $response->assertJsonPath('totals.spend', 5000)
            ->assertJsonPath('totals.active_campaigns', 1)
            ->assertJsonPath('campaigns.0.name', 'Ramadan Sales')
            ->assertJsonPath('campaigns.0.result_label', 'Purchases')
            ->assertJsonPath('campaigns.0.results', 40)
            ->assertJsonPath('campaigns.0.cost_per_result', 100)
            ->assertJsonPath('campaigns.0.daily_budget', 500)
            // An awareness campaign is judged on reach, not purchases.
            ->assertJsonPath('campaigns.1.result_label', 'Reach')
            ->assertJsonPath('campaigns.1.results', 120000);
    }

    public function test_an_expired_token_is_flagged_on_the_connection(): void
    {
        $this->fakeMeta();
        $this->connect();
        Cache::flush();
        Http::swap(new \Illuminate\Http\Client\Factory); // drop the earlier stubs, which would still match first
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 400)]);

        $this->withToken($this->jwt)->getJson('/api/meta/campaigns')->assertStatus(422)
            ->assertJsonPath('message', 'Your Meta token has expired or was revoked — generate a new one and reconnect.');
        $this->assertSame('invalid', MetaConnection::first()->status);
    }

    public function test_the_ai_review_reads_the_numbers_and_is_billed_as_ai_usage(): void
    {
        PlatformSetting::set('openrouter_api_key', 'test-key');
        $this->fakeMeta();
        $this->connect();
        Http::fake([
            '*/models' => Http::response(['data' => [['id' => 'google/gemini-2.5-pro', 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002']]]]),
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'الخلاصة: Ramadan Sales شغالة كويس.']]],
                'usage' => ['prompt_tokens' => 1500, 'completion_tokens' => 500, 'total_tokens' => 2000],
            ]),
        ]);

        $this->withToken($this->jwt)->postJson('/api/meta/analyse', ['period' => 'last_30d', 'language' => 'ar'])
            ->assertOk()->assertJsonPath('analysis', 'الخلاصة: Ramadan Sales شغالة كويس.');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/chat/completions')
            && str_contains($r['messages'][1]['content'], 'Ramadan Sales')
            && ! str_contains(json_encode($r->data()), self::TOKEN));
        $this->assertSame(1, UsageEvent::where('service_type', ServiceType::Ai)->count());
    }
}
