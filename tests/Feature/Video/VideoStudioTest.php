<?php

namespace Tests\Feature\Video;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Auth\JwtService;
use App\Services\Video\VideoModelCatalog;
use App\Services\Video\VideoStitcher;
use App\Services\Video\VideoStudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * The Video Studio's money and lifecycle rules: a film is planned and
 * priced before anything renders, can't start unless the wallet covers it,
 * is billed per delivered shot and never above its quote, and a shot the
 * content filter blocks costs nothing.
 */
class VideoStudioTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
        PlatformSetting::set('openrouter_api_key', 'test-key');

        $this->company = Company::factory()->create();
        $this->company->wallet()->create(['balance' => 100]);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = app(JwtService::class)->issue($user, 'user')['token'];

        $this->stitcher(available: false);
    }

    private function stitcher(bool $available): void
    {
        $mock = Mockery::mock(VideoStitcher::class);
        $mock->shouldReceive('available')->andReturn($available);
        $mock->shouldReceive('still')->andReturnUsing(fn ($in, $out) => file_put_contents($out, 'jpg'));
        $mock->shouldReceive('duration')->andReturn(8.0);
        $mock->shouldReceive('stitch')->andReturnUsing(fn ($paths, $out) => file_put_contents($out, str_repeat('v', 4096)));
        $this->app->instance(VideoStitcher::class, $mock);
    }

    /** @param array<string, mixed> $job what GET /videos/{id} returns */
    private function fakeUpstream(int $shots, array $job = [], int $submitStatus = 202): void
    {
        $storyboard = [
            'title' => 'Morning Ritual',
            'logline' => 'A Cairo morning built around one cup of coffee.',
            'style_bible' => 'Warm golden-hour light, 35mm anamorphic.',
            'characters' => [['id' => 'C1', 'name' => 'Omar', 'reference' => 1, 'description' => 'Egyptian man, 30, short black hair, white linen shirt.']],
            'products' => [],
            'shots' => array_map(fn ($i) => [
                'duration' => 99, // deliberately wrong — the server must override it
                'description' => "Shot {$i} description, Omar (C1) on a Zamalek balcony.",
                'characters' => ['C1'], 'products' => [],
                'dialogue' => [['speaker' => 'C1', 'line' => 'صباح الفل']],
                'voiceover' => null, 'sound' => 'oud and street ambience',
            ], range(1, $shots)),
        ];

        Http::fake([
            '*/videos/models' => Http::response(['data' => [[
                'id' => 'bytedance/seedance-2.0',
                'pricing_skus' => ['video_tokens' => '0.000007', 'video_tokens_1080p' => '0.0000077'],
            ]]]),
            '*/models' => Http::response(['data' => [[
                'id' => 'google/gemini-2.5-pro', 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
            ]]]),
            '*/chat/completions' => Http::response([
                'id' => 'gen-1',
                'choices' => [['message' => ['role' => 'assistant', 'content' => "```json\n".json_encode($storyboard)."\n```"]]],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 1000, 'total_tokens' => 2000],
            ]),
            // A closure, not a fixed response: a fixed one's body stream is
            // drained by the first download and every later shot gets 0 bytes.
            '*/videos/job-*/content*' => fn () => Http::response(str_repeat('m', 4096), 200, ['Content-Type' => 'video/mp4']),
            '*/videos/job-*' => Http::response($job + ['id' => 'job-1', 'status' => 'completed', 'usage' => ['cost' => 1.0]]),
            '*/videos' => Http::sequence()->push(['id' => 'job-1', 'status' => 'pending'], $submitStatus)->whenEmpty(Http::response(['id' => 'job-2', 'status' => 'pending'], $submitStatus)),
        ]);
    }

    private function draft(int $duration = 8): array
    {
        return $this->withToken($this->token)->postJson('/api/services/video/projects', [
            'prompt' => 'A 30-year-old Egyptian man enjoys his morning coffee on a Cairo balcony.',
            'model' => 'bytedance/seedance-2.0',
            'resolution' => '720p',
            'aspect_ratio' => '9:16',
            'duration' => $duration,
            'generate_audio' => true,
            'language' => 'ar-EG',
            'style' => 'cinematic',
            'references' => [['data' => self::PNG, 'label' => 'Omar', 'role' => 'character']],
        ])->assertCreated()->json();
    }

    public function test_a_brief_becomes_a_priced_storyboard_without_rendering_anything(): void
    {
        $this->fakeUpstream(shots: 3);

        $project = $this->draft(duration: 24);

        $this->assertSame('draft', $project['status']);
        $this->assertSame('Morning Ritual', $project['title']);
        $this->assertCount(3, $project['shots']);
        // The LLM said 99s per shot; the server's own split wins.
        $this->assertSame([8, 8, 8], array_column($project['shots'], 'duration'));
        $this->assertGreaterThan(0, $project['estimated_cost']);
        $this->assertSame('صباح الفل', $project['shots'][0]['dialogue'][0]['line']);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/videos'));
        // The only charge so far is the planning LLM call, on the AI service.
        $this->assertSame(0, UsageEvent::where('service_type', ServiceType::Video)->count());
        $this->assertSame(1, UsageEvent::where('service_type', ServiceType::Ai)->count());
    }

    public function test_a_single_shot_film_renders_bills_once_and_completes(): void
    {
        $this->fakeUpstream(shots: 1);
        $project = $this->draft(duration: 8);

        $this->withToken($this->token)->postJson("/api/services/video/projects/{$project['id']}/render")
            ->assertOk()->assertJsonPath('status', 'rendering');

        Http::assertSent(function ($r) {
            if ($r->method() !== 'POST' || ! str_ends_with($r->url(), '/videos')) {
                return false;
            }

            return $r['model'] === 'bytedance/seedance-2.0'
                && $r['duration'] === 8
                && $r['aspect_ratio'] === '9:16'
                && str_starts_with($r['input_references'][0]['image_url']['url'], 'data:image/png;base64,')
                && str_contains($r['prompt'], 'Must match reference image 1 exactly')
                && str_contains($r['prompt'], 'Warm golden-hour light');
        });

        $this->travel(1)->minutes();
        app(VideoStudioService::class)->advanceAll();

        $fresh = VideoProject::find($project['id']);
        $this->assertSame('completed', $fresh->status);
        Storage::disk('local')->assertExists($fresh->output_path);

        $event = UsageEvent::where('service_type', ServiceType::Video)->sole();
        $this->assertEquals(1.0, (float) $event->raw_cost_to_pingly);
        // raw $1.00 × 2x margin = $2.00, unless the quote was lower.
        $this->assertEquals(min(2.0, (float) $fresh->shots[0]->quoted_amount), (float) $event->billed_amount_to_client);
        $this->assertEquals((float) $event->billed_amount_to_client, (float) $fresh->billed_total);
    }

    public function test_a_shot_is_never_billed_above_its_quote(): void
    {
        $this->fakeUpstream(shots: 1, job: ['usage' => ['cost' => 40.0]]);
        $project = $this->draft(duration: 8);
        $this->withToken($this->token)->postJson("/api/services/video/projects/{$project['id']}/render")->assertOk();

        $this->travel(1)->minutes();
        app(VideoStudioService::class)->advanceAll();

        $shot = VideoProject::find($project['id'])->shots[0];
        $this->assertEquals((float) $shot->quoted_amount, (float) UsageEvent::where('service_type', ServiceType::Video)->sole()->billed_amount_to_client);
    }

    public function test_rendering_needs_the_balance_to_cover_the_whole_quote(): void
    {
        $this->fakeUpstream(shots: 1);
        $project = $this->draft(duration: 8);
        $this->company->wallet()->update(['balance' => 0.01]);

        $this->withToken($this->token)->postJson("/api/services/video/projects/{$project['id']}/render")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Not enough balance — top up at least $'.number_format($project['estimated_cost'] - 0.01, 2).' to render this video.']);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/videos'));
    }

    public function test_a_shot_blocked_by_the_content_filter_costs_nothing_and_fails_the_project(): void
    {
        $this->fakeUpstream(shots: 1, job: ['status' => 'failed', 'error' => 'Content policy violation', 'usage' => null]);
        $project = $this->draft(duration: 8);
        $this->withToken($this->token)->postJson("/api/services/video/projects/{$project['id']}/render")->assertOk();

        $this->travel(1)->minutes();
        app(VideoStudioService::class)->advanceAll();

        $fresh = VideoProject::find($project['id']);
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('content filter', $fresh->error);
        $this->assertSame(0, UsageEvent::where('service_type', ServiceType::Video)->count());
    }

    public function test_a_multi_shot_film_is_refused_cleanly_without_ffmpeg(): void
    {
        $this->fakeUpstream(shots: 2);
        $project = $this->draft(duration: 16);

        $this->withToken($this->token)->postJson("/api/services/video/projects/{$project['id']}/render")
            ->assertStatus(422)->assertJsonPath('message', 'Videos longer than one shot are temporarily unavailable — contact support.');
    }

    public function test_a_multi_shot_film_is_rendered_shot_by_shot_and_stitched(): void
    {
        $this->stitcher(available: true);
        $this->fakeUpstream(shots: 3);
        $project = $this->draft(duration: 24);

        $this->withToken($this->token)->postJson("/api/services/video/projects/{$project['id']}/render")->assertOk();
        $this->travel(1)->minutes();
        app(VideoStudioService::class)->advanceAll();
        $this->travel(1)->minutes();
        app(VideoStudioService::class)->advanceAll();

        $fresh = VideoProject::find($project['id']);
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(3, UsageEvent::where('service_type', ServiceType::Video)->count());
        Storage::disk('local')->assertExists($fresh->output_path);
    }

    public function test_media_needs_a_valid_signature_and_projects_are_private(): void
    {
        $this->fakeUpstream(shots: 1);
        $project = $this->draft(duration: 8);

        $this->get("/api/video-media/{$project['id']}/reference/0")->assertForbidden();
        $this->get($project['references'][0]['url'] ?? '')->assertOk();

        $other = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $other->id]);
        $otherToken = app(JwtService::class)->issue($otherUser, 'user')['token'];
        $this->withToken($otherToken)->getJson("/api/services/video/projects/{$project['id']}")->assertNotFound();
    }

    public function test_shot_lengths_fit_what_each_model_can_render(): void
    {
        $catalog = app(VideoModelCatalog::class);

        $this->assertSame([8], $catalog->planShotDurations('bytedance/seedance-2.0', 8));
        $this->assertSame(60, array_sum($catalog->planShotDurations('bytedance/seedance-2.0', 60)));
        // Veo only renders 4/6/8s — every shot snaps to one of those.
        foreach ($catalog->planShotDurations('google/veo-3.1', 30) as $d) {
            $this->assertContains($d, [4, 6, 8]);
        }
    }
}
