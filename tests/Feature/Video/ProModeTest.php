<?php

namespace Tests\Feature\Video;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Auth\JwtService;
use App\Services\Video\VideoStitcher;
use App\Services\Video\VideoStudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Pro mode: short shots, each animated from a painted, product-accurate
 * first frame; the last shot lands on a painted hero frame.
 */
class ProModeTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        PlatformSetting::set('openrouter_api_key', 'test-key');

        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 100]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->token = app(JwtService::class)->issue($user, 'user')['token'];

        $stitcher = Mockery::mock(VideoStitcher::class);
        $stitcher->shouldReceive('available')->andReturn(true);
        $stitcher->shouldReceive('still')->andReturnUsing(fn ($in, $out) => file_put_contents($out, 'jpg'));
        $stitcher->shouldReceive('duration')->andReturn(14.0);
        $stitcher->shouldReceive('stitch')->andReturnUsing(fn ($paths, $out) => file_put_contents($out, str_repeat('v', 4096)));
        $this->app->instance(VideoStitcher::class, $stitcher);

        $shot = fn ($i) => ['duration' => 5, 'description' => "Shot {$i} action.", 'first_frame' => "Shot {$i} opening still.", 'end_frame' => "Hero layout {$i}.",
            'characters' => [], 'products' => ['P1'], 'dialogue' => [], 'voiceover' => null, 'sound' => null];
        $storyboard = ['title' => 'Drop', 'logline' => 'x', 'style_bible' => 'Warm sun.', 'characters' => [],
            'products' => [['id' => 'P1', 'name' => 'Pharaoh hoodie', 'reference' => 1, 'description' => 'Beige hoodie, pharaoh print on the back.']],
            'shots' => [$shot(1), $shot(2), $shot(3)]];

        Http::fake([
            '*/videos/models' => Http::response(['data' => []]),
            '*/models' => Http::response(['data' => []]),
            '*/chat/completions' => fn (Request $r) => isset($r['modalities'])
                ? Http::response(['choices' => [['message' => ['content' => '', 'images' => [['image_url' => ['url' => 'data:image/png;base64,'.self::PNG]]]]]], 'usage' => ['cost' => 0.10, 'total_tokens' => 1]])
                : Http::response(['choices' => [['message' => ['content' => json_encode($storyboard)]]], 'usage' => ['total_tokens' => 100]]),
            '*/videos/job-*/content*' => fn () => Http::response(str_repeat('m', 4096), 200, ['Content-Type' => 'video/mp4']),
            '*/videos/job-*' => Http::response(['id' => 'job-1', 'status' => 'completed', 'usage' => ['cost' => 0.50]]),
            '*/videos' => Http::response(['id' => 'job-1', 'status' => 'pending'], 202),
        ]);
    }

    private function draft(bool $pro = true): array
    {
        return $this->withToken($this->token)->postJson('/api/services/video/projects', [
            'prompt' => 'Four hoodies fly out of a tote bag and land on the pavement.',
            'model' => 'bytedance/seedance-2.0', 'resolution' => '720p', 'aspect_ratio' => '9:16', 'duration' => 14,
            'keyframes' => $pro,
            'references' => [['data' => 'data:image/png;base64,'.self::PNG, 'label' => 'Pharaoh hoodie', 'role' => 'product']],
        ])->assertCreated()->json();
    }

    public function test_pro_mode_plans_short_shots_and_prices_the_painted_frames(): void
    {
        $pro = $this->draft();
        $this->assertTrue($pro['keyframes']);
        $this->assertSame([5, 5, 4], array_column($pro['shots'], 'duration'));
        // The director was asked for opening and hero frames.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/chat/completions') && ! isset($r['modalities'])
            && str_contains(json_encode($r['messages'][1]['content']), 'PRO MODE'));

        $plain = $this->draft(pro: false);
        $this->assertFalse($plain['keyframes']);
        $this->assertCount(1, $plain['shots']); // 14 s fits one ordinary shot
    }

    public function test_every_shot_starts_on_a_painted_frame_and_the_film_ends_on_a_hero_frame(): void
    {
        $project = $this->draft();
        $this->withToken($this->token)->postJson("/api/services/video/projects/{$project['id']}/render")->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->travel(1)->minutes();
            app(VideoStudioService::class)->advanceAll();
        }

        $fresh = VideoProject::with('shots')->find($project['id']);
        $this->assertSame('completed', $fresh->status);

        // 3 opening frames + 1 hero end frame were painted from the product photo.
        $this->assertCount(4, collect(Http::recorded())->filter(fn ($p) => isset($p[0]['modalities'])));
        $videos = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/videos'))->values();
        $this->assertCount(3, $videos);
        $videos->each(fn ($r) => $this->assertFalse(isset($r['input_references'])));
        $this->assertSame(['first_frame'], array_column($videos[0]['frame_images'], 'frame_type'));
        $this->assertSame(['first_frame', 'last_frame'], array_column($videos[2]['frame_images'], 'frame_type'));

        // Later frames are painted with shot 1's frame for continuity.
        Http::assertSent(fn ($r) => isset($r['modalities']) && str_contains($r['messages'][0]['content'][0]['text'], 'OPENING frame of shot 2')
            && str_contains($r['messages'][0]['content'][0]['text'], 'the first shot of this film') && count($r['messages'][0]['content']) === 3);

        // Each shot is billed its video cost plus its painted frames, never above its quote.
        $events = UsageEvent::where('service_type', ServiceType::Video)->orderBy('id')->get();
        $this->assertEquals([0.6, 0.6, 0.7], $events->map(fn ($e) => round((float) $e->raw_cost_to_pingly, 2))->all());
        foreach ($fresh->shots as $s) {
            $this->assertLessThanOrEqual((float) $s->quoted_amount, (float) $s->billed_amount);
        }

        $show = $this->withToken($this->token)->getJson("/api/services/video/projects/{$project['id']}")->json();
        $this->assertNotNull($show['shots'][0]['keyframe_url']);
        $this->assertNotNull($show['shots'][2]['end_frame_url']);
        $this->get($show['shots'][2]['end_frame_url'])->assertOk();
    }
}
