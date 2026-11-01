<?php

namespace Tests\Feature\Meta;

use App\Enums\ServiceType;
use App\Models\AiImage;
use App\Models\Company;
use App\Models\MetaCampaign;
use App\Models\MetaConnection;
use App\Models\PlatformSetting;
use App\Models\SocialPost;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Image Studio, the AI campaign builder, and the in-app assistant.
 */
class StudioAndCampaignsTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Company $company;

    private string $jwt;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        PlatformSetting::set('openrouter_api_key', 'test-key');
        $this->company = Company::factory()->create();
        $this->company->wallet()->create(['balance' => 50]);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->jwt = app(JwtService::class)->issue($user, 'user')['token'];
    }

    private function connectMeta(): void
    {
        MetaConnection::create([
            'company_id' => $this->company->id, 'access_token' => 'EAAG-system', 'currency' => 'EGP',
            'granted_permissions' => ['ads_read', 'ads_management', 'pages_show_list', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'],
            'ad_account_id' => 'act_1', 'page_id' => '222', 'page_name' => 'Bon El Sabah', 'instagram_account_id' => '333', 'instagram_username' => 'bon',
        ]);
    }

    private function image(): AiImage
    {
        $img = AiImage::create(['company_id' => $this->company->id, 'prompt' => 'كوباية قهوة على رخام', 'model' => 'google/gemini-3-pro-image', 'aspect_ratio' => '1:1', 'path' => 'images/1/1.png']);
        Storage::disk('local')->put('images/1/1.png', base64_decode(self::PNG));

        return $img;
    }

    public function test_an_image_is_generated_stored_and_billed_at_the_real_reported_cost(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => '', 'images' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.self::PNG]]]]]],
            'usage' => ['total_tokens' => 1300, 'cost' => 0.134],
        ])]);

        $res = $this->withToken($this->jwt)->postJson('/api/services/images', [
            'prompt' => 'Coffee cup on marble, morning light', 'model' => 'google/gemini-3-pro-image', 'aspect_ratio' => '4:5',
            'references' => ['data:image/png;base64,'.self::PNG],
        ])->assertCreated();

        Storage::disk('local')->assertExists(AiImage::sole()->path);
        $this->get($res->json('url'))->assertOk();

        $event = UsageEvent::where('service_type', ServiceType::Ai)->sole();
        $this->assertEquals(0.134, (float) $event->raw_cost_to_pingly); // usage.cost, not a token estimate
        Http::assertSent(fn ($r) => $r['modalities'] === ['image', 'text'] && $r['image_config']['aspect_ratio'] === '4:5' && $r['usage']['include'] === true
            && $r['messages'][0]['content'][1]['type'] === 'image_url');
    }

    public function test_a_declined_image_costs_nothing_and_says_so(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => "I can't create that."]]], 'usage' => ['cost' => 0.001]])]);

        $this->withToken($this->jwt)->postJson('/api/services/images', ['prompt' => 'something', 'model' => 'google/gemini-3-pro-image', 'aspect_ratio' => '1:1'])
            ->assertStatus(422);
        $this->assertSame(0, AiImage::count());
        $this->assertSame(0, UsageEvent::count());
    }

    public function test_an_image_is_published_to_the_page_and_instagram(): void
    {
        $this->connectMeta();
        $img = $this->image();
        Http::fake([
            'graph.facebook.com/*/222?*' => Http::response(['access_token' => 'PAGE']),
            'graph.facebook.com/*/222/photos' => Http::response(['id' => 'ph1', 'post_id' => '222_9']),
            'graph.facebook.com/*/222_9?*' => Http::response(['permalink_url' => 'https://www.facebook.com/222/posts/9']),
            'graph.facebook.com/*/333/media' => Http::response(['id' => 'cont1']),
            'graph.facebook.com/*/cont1?*' => Http::response(['status_code' => 'FINISHED']),
            'graph.facebook.com/*/333/media_publish' => Http::response(['id' => 'ig9']),
            'graph.facebook.com/*/ig9?*' => Http::response(['permalink' => 'https://www.instagram.com/p/x/']),
        ]);

        $this->withToken($this->jwt)->postJson("/api/services/images/{$img->id}/publish", ['platforms' => ['facebook', 'instagram'], 'caption' => 'صباح الخير'])
            ->assertOk();

        $this->assertSame(['published', 'published'], SocialPost::orderBy('platform')->pluck('status')->all());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/333/media') && str_contains($r['image_url'], '/api/image-media/'.$img->id) && ! isset($r['media_type']));
    }

    public function test_the_ai_drafts_a_campaign_and_the_numbers_are_kept_sane(): void
    {
        $this->connectMeta();
        $img = $this->image();
        Http::fake([
            '*/models' => Http::response(['data' => []]),
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'name' => 'Coffee — WhatsApp', 'daily_budget' => 300, 'days' => 400, 'countries' => ['EG', 'XX'],
                'age_min' => 12, 'age_max' => 99, 'gender' => 'female', 'primary_text' => 'صباحك قهوة', 'headline' => 'بن الصباح',
                'call_to_action' => 'SHOP_NOW', 'link' => null, 'rationale' => 'ستات القاهرة',
            ])]]], 'usage' => ['total_tokens' => 10]]),
        ]);

        $res = $this->withToken($this->jwt)->postJson('/api/meta/campaigns/builder', [
            'goal' => 'messages', 'brief' => 'عايز ناس تكلمنا واتساب يطلبوا قهوة', 'ai_image_id' => $img->id,
        ])->assertCreated();

        $res->assertJsonPath('status', 'draft')
            ->assertJsonPath('plan.days', 90)                      // clamped
            ->assertJsonPath('plan.countries', ['EG'])             // unknown country dropped
            ->assertJsonPath('plan.age_min', 18)
            ->assertJsonPath('plan.age_max', 65)
            ->assertJsonPath('plan.call_to_action', 'WHATSAPP_MESSAGE') // only CTAs valid for the goal
            ->assertJsonPath('total_budget', 27000);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_creating_on_meta_is_always_paused_and_switching_on_is_explicit(): void
    {
        $this->connectMeta();
        $img = $this->image();
        $campaign = MetaCampaign::create([
            'company_id' => $this->company->id, 'goal' => 'traffic', 'brief' => 'x', 'ai_image_id' => $img->id, 'status' => 'draft',
            'plan' => ['name' => 'Test', 'daily_budget' => 250, 'days' => 7, 'countries' => ['EG'], 'age_min' => 22, 'age_max' => 45,
                'gender' => 'all', 'primary_text' => 'hi', 'headline' => 'hey', 'call_to_action' => 'SHOP_NOW', 'link' => 'https://shop.example'],
        ]);
        Http::fake([
            'graph.facebook.com/*/act_1/campaigns' => Http::response(['id' => 'c1']),
            'graph.facebook.com/*/act_1/adsets' => Http::response(['id' => 's1']),
            'graph.facebook.com/*/act_1/adimages' => Http::response(['images' => ['x.png' => ['hash' => 'h1']]]),
            'graph.facebook.com/*/act_1/adcreatives' => Http::response(['id' => 'cr1']),
            'graph.facebook.com/*/act_1/ads' => Http::response(['id' => 'a1']),
            'graph.facebook.com/*/a1' => Http::response(['success' => true]),
            'graph.facebook.com/*/s1' => Http::response(['success' => true]),
            'graph.facebook.com/*/c1' => Http::response(['success' => true]),
        ]);

        $this->withToken($this->jwt)->postJson("/api/meta/campaigns/builder/{$campaign->id}/launch")->assertOk()->assertJsonPath('status', 'created');

        foreach (['/campaigns', '/adsets', '/ads'] as $path) {
            Http::assertSent(fn ($r) => str_ends_with($r->url(), 'act_1'.$path) && $r['status'] === 'PAUSED');
        }
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/adsets') && $r['daily_budget'] === '25000'
            && json_decode($r['targeting'], true)['geo_locations']['countries'] === ['EG']);
        Http::assertNotSent(fn ($r) => ($r['status'] ?? null) === 'ACTIVE');

        $this->withToken($this->jwt)->postJson("/api/meta/campaigns/builder/{$campaign->id}/status", ['status' => 'ACTIVE'])->assertOk()->assertJsonPath('status', 'active');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/c1') && $r['status'] === 'ACTIVE');
    }

    public function test_a_failure_halfway_deletes_the_half_built_campaign(): void
    {
        $this->connectMeta();
        $img = $this->image();
        $campaign = MetaCampaign::create([
            'company_id' => $this->company->id, 'goal' => 'awareness', 'brief' => 'x', 'ai_image_id' => $img->id, 'status' => 'draft',
            'plan' => ['name' => 'T', 'daily_budget' => 100, 'days' => 3, 'countries' => ['EG'], 'age_min' => 18, 'age_max' => 65, 'gender' => 'all', 'primary_text' => 'a', 'headline' => 'b', 'call_to_action' => 'LEARN_MORE', 'link' => null],
        ]);
        Http::fake([
            'graph.facebook.com/*/act_1/campaigns' => Http::response(['id' => 'c1']),
            'graph.facebook.com/*/act_1/adsets' => Http::response(['error' => ['code' => 100, 'message' => 'Invalid budget']], 400),
            'graph.facebook.com/*/c1' => Http::response(['success' => true]),
        ]);

        $this->withToken($this->jwt)->postJson("/api/meta/campaigns/builder/{$campaign->id}/launch")->assertStatus(422);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/c1') && $r['status'] === 'DELETED');
        $this->assertSame('failed', $campaign->fresh()->status);
        $this->assertNull($campaign->fresh()->campaign_id);
    }

    public function test_the_assistant_knows_the_clients_setup_but_not_their_secrets(): void
    {
        $this->connectMeta();
        Http::fake([
            '*/models' => Http::response(['data' => []]),
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'اتبع الخطوات دي…']]], 'usage' => ['total_tokens' => 5]]),
        ]);

        $this->withToken($this->jwt)->postJson('/api/assistant', ['messages' => [['role' => 'user', 'content' => 'إزاي أنشر على إنستجرام؟']], 'page' => 'Image Studio'])
            ->assertOk()->assertJsonPath('reply', 'اتبع الخطوات دي…');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/chat/completions') && str_contains($r['messages'][0]['content'], 'Instagram @bon')
            && ! str_contains(json_encode($r->data()), 'EAAG-system'));
    }
}
