<?php

namespace Tests\Feature\Video;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\Auth\JwtService;
use App\Services\Video\VideoDirector;
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
 * Seedance refuses photos of real people, so a film with one moves to
 * Kling 3.0 Pro — which takes no reference images, only a first frame, so
 * each shot's opening frame is painted first from the client's photos.
 */
class RealFaceAndKeyframeTest extends TestCase
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
        $stitcher->shouldReceive('available')->andReturn(false);
        $stitcher->shouldReceive('still')->andReturnUsing(fn ($in, $out) => file_put_contents($out, 'jpg'));
        $stitcher->shouldReceive('duration')->andReturn(8.0);
        $this->app->instance(VideoStitcher::class, $stitcher);

        $storyboard = [
            'title' => 'Owner Speaks', 'logline' => 'x', 'style_bible' => 'Warm daylight.',
            'characters' => [['id' => 'C1', 'name' => 'Hassan', 'reference' => 1, 'description' => 'The owner.']], 'products' => [],
            'shots' => [['duration' => 8, 'description' => 'Hassan smiles behind the counter.', 'characters' => ['C1'], 'products' => [], 'dialogue' => [], 'voiceover' => null, 'sound' => null]],
        ];

        Http::fake([
            '*/videos/models' => Http::response(['data' => [['id' => 'kwaivgi/kling-v3.0-pro', 'pricing_skus' => ['duration_seconds' => '0.112', 'duration_seconds_with_audio' => '0.168']]]]),
            '*/models' => Http::response(['data' => []]),
            // The same endpoint plans the storyboard and paints the keyframe.
            '*/chat/completions' => fn (Request $r) => isset($r['modalities'])
                ? Http::response(['choices' => [['message' => ['content' => '', 'images' => [['image_url' => ['url' => 'data:image/png;base64,'.self::PNG]]]]]], 'usage' => ['cost' => 0.13, 'total_tokens' => 1300]])
                : Http::response(['choices' => [['message' => ['content' => json_encode($storyboard)]]], 'usage' => ['total_tokens' => 100]]),
            '*/videos/job-*/content*' => fn () => Http::response(str_repeat('m', 4096), 200, ['Content-Type' => 'video/mp4']),
            '*/videos/job-*' => Http::response(['id' => 'job-1', 'status' => 'completed', 'usage' => ['cost' => 1.0]]),
            '*/videos' => Http::response(['id' => 'job-1', 'status' => 'pending'], 202),
        ]);
    }

    private function draft(array $extra = [], bool $realPerson = true)
    {
        return $this->withToken($this->token)->postJson('/api/services/video/projects', $extra + [
            'prompt' => 'The shop owner welcomes customers to his bakery in Nasr City.',
            'model' => 'bytedance/seedance-2.0',
            'resolution' => '1080p',
            'aspect_ratio' => '3:4',
            'duration' => 8,
            'references' => [['data' => 'data:image/png;base64,'.self::PNG, 'label' => 'Hassan', 'role' => 'character', 'real_person' => $realPerson]],
        ]);
    }

    public function test_a_real_persons_photo_needs_their_permission(): void
    {
        $this->draft()->assertStatus(422)->assertJsonValidationErrors('real_person_consent');
    }

    public function test_a_real_person_moves_the_film_from_seedance_to_kling_and_says_so(): void
    {
        $project = $this->draft(['real_person_consent' => true])->assertCreated()->json();

        $this->assertSame('kwaivgi/kling-v3.0-pro', $project['model']);
        $this->assertSame('720p', $project['resolution']);   // Kling's only resolution
        $this->assertSame('9:16', $project['aspect_ratio']); // portrait stays portrait
        $this->assertStringContainsString('Kling 3.0 Pro', $project['notice']);
        $this->assertTrue($project['references'][0]['real_person']);
    }

    public function test_without_a_real_person_seedance_is_kept(): void
    {
        Http::fake(['*/videos/models' => Http::response(['data' => []])]);
        $project = $this->draft(['aspect_ratio' => '9:16', 'resolution' => '720p'], realPerson: false)->assertCreated()->json();

        $this->assertSame('bytedance/seedance-2.0', $project['model']);
        $this->assertNull($project['notice']);
    }

    public function test_kling_renders_from_a_painted_keyframe_and_bills_it_with_the_shot(): void
    {
        $project = $this->draft(['real_person_consent' => true])->json();

        $this->withToken($this->token)->postJson("/api/services/video/projects/{$project['id']}/render")->assertOk();

        // The keyframe was painted from the client's photo first…
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/chat/completions') && isset($r['modalities'])
            && $r['model'] === 'google/gemini-3-pro-image' && $r['image_config']['aspect_ratio'] === '9:16'
            && $r['messages'][0]['content'][1]['type'] === 'image_url'
            && str_contains($r['messages'][0]['content'][0]['text'], 'the same person: face'));
        // …then Kling animated it: a first frame, no reference images.
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/videos')
            && $r['model'] === 'kwaivgi/kling-v3.0-pro'
            && $r['frame_images'][0]['frame_type'] === 'first_frame'
            && ! isset($r['input_references']) && ! isset($r['seed']));

        $this->travel(1)->minutes();
        app(VideoStudioService::class)->advanceAll();

        $fresh = VideoProject::find($project['id']);
        $this->assertSame('completed', $fresh->status);
        $event = UsageEvent::where('service_type', ServiceType::Video)->sole();
        $this->assertEquals(1.13, (float) $event->raw_cost_to_pingly); // video $1.00 + keyframe $0.13
        $this->assertLessThanOrEqual((float) $fresh->shots[0]->quoted_amount, (float) $event->billed_amount_to_client);
    }

    public function test_rewriting_a_shot_throws_away_its_old_keyframe(): void
    {
        $project = $this->draft(['real_person_consent' => true])->json();
        $shot = VideoProject::find($project['id'])->shots()->first();
        $shot->update(['keyframe_path' => 'x.png', 'keyframe_cost' => 0.13]);

        $this->withToken($this->token)->putJson("/api/services/video/projects/{$project['id']}/shots/{$shot->id}", ['description' => 'Hassan hands a customer a fresh loaf.'])->assertOk();

        $this->assertNull($shot->fresh()->keyframe_path);
    }

    public function test_the_director_sees_the_product_photos_and_the_prompt_protects_their_print(): void
    {
        Http::fake(['*/videos/models' => Http::response(['data' => []])]);
        $this->withToken($this->token)->postJson('/api/services/video/projects', [
            'prompt' => 'Four hoodies fly out of a tote bag and land on the pavement.',
            'model' => 'bytedance/seedance-2.0', 'resolution' => '720p', 'aspect_ratio' => '9:16', 'duration' => 8,
            'references' => [['data' => 'data:image/png;base64,'.self::PNG, 'label' => 'WhatsApp Image 2026', 'role' => 'product']],
        ])->assertCreated();

        // The planner got the actual image, not just its file name.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/chat/completions') && ! isset($r['modalities'])
            && is_array($r['messages'][1]['content'])
            && collect($r['messages'][1]['content'])->contains(fn ($part) => ($part['type'] ?? null) === 'image_url'));

        $board = ['style_bible' => 'x', 'characters' => [], 'products' => [['id' => 'P1', 'kind' => 'product', 'name' => 'Hoodie', 'reference' => 1, 'description' => 'Brown hoodie with a back print.']]];
        $prompt = app(VideoDirector::class)->composePrompt($board, ['description' => 'It flies.', 'duration' => 8, 'products' => ['P1']], 0, 1, 1, false);
        $this->assertStringContainsString('same print, graphic, text and logo', $prompt);
        $this->assertStringNotContainsString('skin tone, hair and proportions', $prompt);
    }
}
