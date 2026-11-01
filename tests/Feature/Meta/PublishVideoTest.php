<?php

namespace Tests\Feature\Meta;

use App\Models\Company;
use App\Models\MetaConnection;
use App\Models\PlatformSetting;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Auth\JwtService;
use App\Services\Meta\MetaPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A finished Video Studio film goes to the client's own Facebook Page and
 * Instagram (as a Reel), with a caption the AI can write.
 */
class PublishVideoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $jwt;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->company = Company::factory()->create();
        $this->company->wallet()->create(['balance' => 20]);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->jwt = app(JwtService::class)->issue($user, 'user')['token'];

        $this->project = VideoProject::create([
            'company_id' => $this->company->id, 'prompt' => 'إعلان قهوة', 'model' => 'bytedance/seedance-2.0',
            'resolution' => '720p', 'aspect_ratio' => '9:16', 'target_duration' => 8, 'status' => 'completed',
            'title' => 'Morning Ritual', 'storyboard' => ['title' => 'Morning Ritual', 'logline' => 'A Cairo morning'],
            'output_path' => 'video/1/1/film.mp4',
        ]);
        Storage::disk('local')->put('video/1/1/film.mp4', str_repeat('v', 2048));
    }

    private function connect(array $permissions = ['pages_manage_posts', 'pages_show_list', 'instagram_content_publish', 'instagram_basic']): void
    {
        MetaConnection::create([
            'company_id' => $this->company->id, 'access_token' => 'EAAG-system-user', 'granted_permissions' => $permissions,
            'ad_account_id' => 'act_1', 'page_id' => '222', 'page_name' => 'Bon El Sabah',
            'instagram_account_id' => '333', 'instagram_username' => 'bonelsabah',
        ]);
    }

    private function fakeMeta(string $igStatus = 'FINISHED'): void
    {
        Http::fake([
            'graph.facebook.com/*/222?*' => Http::response(['access_token' => 'PAGE-TOKEN', 'id' => '222']),
            'graph.facebook.com/*/222/videos' => Http::response(['id' => 'fbvid1']),
            'graph.facebook.com/*/fbvid1?*' => Http::response(['permalink_url' => '/bonelsabah/videos/fbvid1']),
            'graph.facebook.com/*/333/media' => Http::response(['id' => 'container9']),
            'graph.facebook.com/*/container9?*' => Http::response(['status_code' => $igStatus]),
            'graph.facebook.com/*/333/media_publish' => Http::response(['id' => 'igmedia5']),
            'graph.facebook.com/*/igmedia5?*' => Http::response(['permalink' => 'https://www.instagram.com/reel/abc/']),
        ]);
    }

    public function test_the_video_page_says_where_it_can_be_published(): void
    {
        $this->withToken($this->jwt)->getJson("/api/services/video/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('publishing.targets.facebook.available', false)
            ->assertJsonPath('publishing.targets.facebook.reason', 'Connect your Meta account first (Meta Ads page).');

        $this->connect(permissions: ['pages_manage_posts', 'pages_show_list']);
        $this->withToken($this->jwt)->getJson("/api/services/video/projects/{$this->project->id}")
            ->assertJsonPath('publishing.targets.facebook.available', true)
            ->assertJsonPath('publishing.targets.facebook.target', 'Bon El Sabah')
            ->assertJsonPath('publishing.targets.instagram.available', false)
            ->assertJsonPath('publishing.targets.instagram.reason', 'Your Meta token is missing: instagram_content_publish, instagram_basic.');
    }

    public function test_it_posts_to_the_facebook_page_with_the_page_token_and_a_fetchable_link(): void
    {
        $this->connect();
        $this->fakeMeta();

        $this->withToken($this->jwt)->postJson("/api/services/video/projects/{$this->project->id}/publish", [
            'platforms' => ['facebook'], 'caption' => 'صباحك قهوة ☕',
        ])->assertOk()
            ->assertJsonPath('posts.0.status', 'published')
            ->assertJsonPath('posts.0.permalink', 'https://www.facebook.com/bonelsabah/videos/fbvid1');

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/222/videos')) {
                return false;
            }

            return $r->header('Authorization')[0] === 'Bearer PAGE-TOKEN'
                && $r['description'] === 'صباحك قهوة ☕'
                && str_contains($r['file_url'], '/api/video-media/'.$this->project->id.'/film')
                && str_contains($r['file_url'], 'signature=');
        });

        // The link Meta fetches really serves the film.
        $url = Http::recorded()->first(fn ($pair) => str_ends_with($pair[0]->url(), '/222/videos'))[0]['file_url'];
        $this->get($url)->assertOk();
    }

    public function test_an_instagram_reel_waits_for_processing_then_goes_live(): void
    {
        $this->connect();
        $this->fakeMeta(igStatus: 'IN_PROGRESS');

        $this->withToken($this->jwt)->postJson("/api/services/video/projects/{$this->project->id}/publish", [
            'platforms' => ['instagram'], 'caption' => 'hello',
        ])->assertOk()->assertJsonPath('posts.0.status', 'processing');

        app(MetaPublisher::class)->advance();
        $this->assertSame('processing', SocialPost::sole()->status);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/media_publish'));

        Http::swap(new Factory);
        $this->fakeMeta(igStatus: 'FINISHED');
        app(MetaPublisher::class)->advance();

        $post = SocialPost::sole();
        $this->assertSame('published', $post->status);
        $this->assertSame('https://www.instagram.com/reel/abc/', $post->permalink);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/media_publish') && $r['creation_id'] === 'container9');
    }

    public function test_instagram_rejecting_the_video_is_reported_not_retried_forever(): void
    {
        $this->connect();
        $this->fakeMeta(igStatus: 'ERROR');
        $this->withToken($this->jwt)->postJson("/api/services/video/projects/{$this->project->id}/publish", ['platforms' => ['instagram']])->assertOk();

        app(MetaPublisher::class)->advance();

        $post = SocialPost::sole();
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('Reels need', $post->error);
    }

    public function test_an_unfinished_video_cannot_be_published(): void
    {
        $this->connect();
        $this->project->update(['status' => 'rendering']);

        $this->withToken($this->jwt)->postJson("/api/services/video/projects/{$this->project->id}/publish", ['platforms' => ['facebook']])
            ->assertStatus(422)->assertJsonPath('message', 'Only a finished video can be published.');
    }

    public function test_the_ai_writes_a_caption_from_the_storyboard(): void
    {
        PlatformSetting::set('openrouter_api_key', 'test-key');
        Http::fake([
            '*/models' => Http::response(['data' => []]),
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => "\"صباحك أحلى مع بن الصباح ☕\n#قهوة\""]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ]),
        ]);

        $this->withToken($this->jwt)->postJson("/api/services/video/projects/{$this->project->id}/caption", ['language' => 'ar-EG'])
            ->assertOk()->assertJsonPath('caption', "صباحك أحلى مع بن الصباح ☕\n#قهوة");

        Http::assertSent(fn ($r) => str_contains($r->url(), '/chat/completions') && str_contains($r['messages'][1]['content'], 'A Cairo morning'));
    }
}
