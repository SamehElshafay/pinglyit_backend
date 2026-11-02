<?php

namespace Tests\Feature\Video;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\VideoFaceSwap;
use App\Models\VideoProject;
use App\Services\Auth\JwtService;
use App\Services\Video\FaceSwapService;
use App\Services\Video\VideoStitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Face swap: a consenting real person's face onto a finished film's actor,
 * through PiAPI, billed per frame only when the swapped video arrives.
 */
class FaceSwapTest extends TestCase
{
    use RefreshDatabase;

    private const FACE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Company $company;

    private VideoProject $project;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        PlatformSetting::set('openrouter_api_key', 'test-key');
        PlatformSetting::set('piapi_api_key', 'piapi-test-key');

        $this->company = Company::factory()->create();
        $this->company->wallet()->create(['balance' => 20]);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = app(JwtService::class)->issue($user, 'user')['token'];

        $this->project = VideoProject::create([
            'company_id' => $this->company->id, 'user_id' => $user->id, 'prompt' => 'x', 'model' => 'bytedance/seedance-2.0',
            'resolution' => '1080p', 'aspect_ratio' => '9:16', 'target_duration' => 14, 'generate_audio' => true,
            'status' => 'completed', 'output_path' => 'video/1/1/film.mp4', 'output_duration' => 14, 'storyboard' => [],
        ]);
        Storage::disk('local')->put('video/1/1/film.mp4', str_repeat('v', 4096));

        $stitcher = Mockery::mock(VideoStitcher::class);
        $stitcher->shouldReceive('available')->andReturn(true);
        $stitcher->shouldReceive('fitForFaceSwap')->andReturnUsing(fn ($in, $out) => file_put_contents($out, str_repeat('s', 4096)));
        $this->app->instance(VideoStitcher::class, $stitcher);
    }

    private function requestSwap(array $extra = [])
    {
        return $this->withToken($this->token)->postJson("/api/services/video/projects/{$this->project->id}/face-swaps", $extra + ['face' => self::FACE, 'consent' => true]);
    }

    public function test_a_face_swap_needs_the_persons_permission(): void
    {
        Http::fake();
        $this->requestSwap(['consent' => false])->assertStatus(422)->assertJsonValidationErrors('consent');
        Http::assertNothingSent();
    }

    public function test_the_film_is_quoted_per_frame_before_swapping(): void
    {
        $show = $this->withToken($this->token)->getJson("/api/services/video/projects/{$this->project->id}")->assertOk();
        $show->assertJsonPath('face_swap.available', true)
            ->assertJsonPath('face_swap.quote.frames', 336); // 14 s × 24 fps
        $this->assertEquals(round(336 * 0.004 * 2, 4), $show->json('face_swap.quote.price')); // × default 2x
    }

    public function test_a_swap_is_sent_to_piapi_and_billed_per_frame_only_once_delivered(): void
    {
        Http::fake([
            'api.piapi.ai/api/v1/task' => Http::response(['code' => 200, 'data' => ['task_id' => 't-1', 'status' => 'pending']]),
            'api.piapi.ai/api/v1/task/t-1' => Http::response(['code' => 200, 'data' => ['task_id' => 't-1', 'status' => 'completed', 'output' => ['video_url' => 'https://cdn.piapi.test/out.mp4']]]),
            'cdn.piapi.test/*' => fn () => Http::response(str_repeat('o', 4096), 200, ['Content-Type' => 'video/mp4']),
        ]);

        $this->requestSwap()->assertCreated()->assertJsonPath('status', 'processing');
        $this->assertSame(0, UsageEvent::count()); // nothing charged on submit

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->hasHeader('x-api-key', 'piapi-test-key')
            && $r['model'] === 'Qubico/video-toolkit' && $r['task_type'] === 'face-swap'
            && str_contains($r['input']['swap_image'], '/face-swap-media/') && str_contains($r['input']['target_video'], '/source'));

        // PiAPI can fetch both files through the signed URLs; nobody else can.
        $sent = collect(Http::recorded())->first(fn ($p) => $p[0]->method() === 'POST')[0];
        $this->get($sent['input']['target_video'])->assertOk();
        $this->get(strtok($sent['input']['swap_image'], '?'))->assertForbidden();

        $this->travel(1)->minutes();
        app(FaceSwapService::class)->advanceAll();

        $swap = VideoFaceSwap::sole();
        $this->assertSame('completed', $swap->status);
        Storage::disk('local')->assertExists($swap->output_path);
        $event = UsageEvent::where('service_type', ServiceType::Video)->sole();
        $this->assertEquals(1.344, (float) $event->raw_cost_to_pingly);
        $this->assertEquals(2.688, (float) $event->billed_amount_to_client);
        $this->assertSame('piapi/face-swap', $event->metadata['model']);
    }

    public function test_a_failed_swap_costs_nothing(): void
    {
        Http::fake([
            'api.piapi.ai/api/v1/task' => Http::response(['code' => 200, 'data' => ['task_id' => 't-2', 'status' => 'pending']]),
            'api.piapi.ai/api/v1/task/t-2' => Http::response(['code' => 200, 'data' => ['status' => 'failed', 'error' => ['message' => 'no face']]]),
        ]);

        $this->requestSwap()->assertCreated();
        $this->travel(1)->minutes();
        app(FaceSwapService::class)->advanceAll();

        $this->assertSame('failed', VideoFaceSwap::sole()->status);
        $this->assertSame(0, UsageEvent::count());
    }

    public function test_it_refuses_unfinished_videos_and_low_balances(): void
    {
        Http::fake();
        $this->company->wallet->update(['balance' => 1]);
        $this->requestSwap()->assertStatus(422)->assertJsonFragment(['message' => 'Not enough balance — the face swap costs $2.69. Top up and try again.']);

        $this->project->update(['status' => 'rendering']);
        $this->requestSwap()->assertStatus(422)->assertJsonFragment(['message' => 'Finish the video first, then swap a face into it.']);
        Http::assertNothingSent();
    }
}
