<?php

namespace Tests\Feature\Video;

use App\Enums\ServiceType;
use App\Models\AdminUser;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\VideoProject;
use App\Services\Ai\AiGatewayService;
use App\Services\Auth\JwtService;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use App\Services\Video\VideoStudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money held for renders in progress, per-client admin controls, and the
 * per-client config merge those controls depend on.
 */
class VideoAdminAndHoldTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return app(JwtService::class)->issue(AdminUser::factory()->create(), 'admin')['token'];
    }

    private function renderingProject(Company $company, float $quote): VideoProject
    {
        $project = VideoProject::create([
            'company_id' => $company->id, 'prompt' => 'x', 'model' => 'bytedance/seedance-2.0',
            'resolution' => '720p', 'aspect_ratio' => '16:9', 'target_duration' => 8, 'status' => 'rendering',
        ]);
        $project->shots()->create(['position' => 0, 'duration' => 8, 'description' => 'x', 'status' => 'submitted', 'quoted_amount' => $quote]);

        return $project;
    }

    public function test_money_promised_to_a_render_cant_be_spent_on_ai_meanwhile(): void
    {
        PlatformSetting::set('openrouter_api_key', 'test-key');
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 20]);
        [, $token] = ApiKey::generate($company, 'Production');
        $this->renderingProject($company, 20);

        $this->withToken($token)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o',
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ])->assertStatus(422)->assertJsonPath('error', 'Wallet balance is empty — top up before making AI requests.');
    }

    public function test_a_finished_render_releases_its_hold(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 20]);
        $project = $this->renderingProject($company, 12);

        $billing = app(BillingEngine::class);
        $this->assertEquals(8.0, $billing->availableBalance($company));

        $project->update(['status' => 'completed']);
        $this->assertEquals(20.0, $billing->availableBalance($company->fresh()));
    }

    public function test_switching_a_service_off_and_on_keeps_the_platform_pricing(): void
    {
        $configs = app(ServiceConfigRepository::class);
        $configs->setPlatformDefault(ServiceType::Ai, ['multiplier' => 5, 'model_overrides' => ['openai/gpt-4o' => 3]]);
        $company = Company::factory()->create();
        $ai = app(AiGatewayService::class);

        $ai->setEnabledFor($company, false);
        $ai->setEnabledFor($company, true);

        $this->assertSame(['openai/gpt-4o'], array_column($ai->availableModelsFor($company), 'id'));
        $this->assertEquals(3.0, $ai->multiplierFor($company, 'openai/gpt-4o'));
    }

    public function test_saving_a_client_multiplier_keeps_the_service_switched_off(): void
    {
        $company = Company::factory()->create();
        $token = $this->adminToken();

        $this->withToken($token)->putJson("/api/admin/clients/{$company->id}/ai-config", ['enabled' => false])->assertOk();
        $this->withToken($token)->putJson("/api/admin/clients/{$company->id}/ai-config", ['multiplier' => 4])
            ->assertOk()->assertJsonPath('ai.enabled', false);

        $this->assertEquals(4.0, app(AiGatewayService::class)->multiplierFor($company, 'openai/gpt-4o'));
    }

    public function test_admin_controls_video_studio_per_client(): void
    {
        $company = Company::factory()->create();
        $token = $this->adminToken();
        $studio = app(VideoStudioService::class);

        $this->withToken($token)->putJson("/api/admin/clients/{$company->id}/video-config", ['enabled' => false, 'multiplier' => 3])
            ->assertOk()->assertJsonPath('video.enabled', false)->assertJsonPath('video.multiplier', 3);
        $this->assertFalse($studio->isEnabledFor($company));
        $this->assertEquals(3.0, $studio->multiplierFor($company, 'google/veo-3.1'));

        // Clearing the multiplier falls back to the platform rate — never 0×.
        $this->withToken($token)->putJson("/api/admin/clients/{$company->id}/video-config", ['multiplier' => null])->assertOk();
        $this->assertEquals((float) config('pingly.video.default_multiplier'), $studio->multiplierFor($company, 'google/veo-3.1'));
        $this->assertFalse($studio->isEnabledFor($company));

        $this->withToken($token)->getJson("/api/admin/clients/{$company->id}")->assertOk()->assertJsonPath('video.enabled', false);
    }

    public function test_admin_sees_every_render_with_its_real_cost(): void
    {
        $company = Company::factory()->create(['name' => 'Bon El Sabah']);
        $company->wallet()->create(['balance' => 20]);
        $project = $this->renderingProject($company, 5);
        $project->shots()->first()->update(['raw_cost' => 2.5]);

        $this->withToken($this->adminToken())->getJson('/api/admin/video/projects')
            ->assertOk()
            ->assertJsonPath('data.0.client', 'Bon El Sabah')
            ->assertJsonPath('data.0.raw_cost', 2.5)
            ->assertJsonPath('data.0.status', 'rendering');
    }
}
