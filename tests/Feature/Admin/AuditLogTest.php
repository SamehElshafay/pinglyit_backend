<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): AdminUser
    {
        return AdminUser::factory()->create();
    }

    private function tokenFor(AdminUser $admin): string
    {
        return app(JwtService::class)->issue($admin, 'admin')['token'];
    }

    public function test_updating_ai_pricing_writes_an_audit_log_entry(): void
    {
        $admin = $this->makeAdmin();

        $this->withToken($this->tokenFor($admin))->putJson('/api/admin/ai/pricing', [
            'multiplier' => 6,
            'model_overrides' => ['openai/gpt-4o' => 3],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'ai_pricing.update',
        ]);

        $entry = $this->withToken($this->tokenFor($admin))->getJson('/api/admin/audit-log')->json('data.0');
        $this->assertSame($admin->name, $entry['admin']);
        $this->assertStringContainsString('6', $entry['change']);
    }

    public function test_updating_a_clients_service_config_logs_it_scoped_to_that_client(): void
    {
        $admin = $this->makeAdmin();
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        $this->withToken($this->tokenFor($admin))
            ->putJson("/api/admin/clients/{$company->id}/ai-config", ['enabled' => false])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'company_id' => $company->id,
            'action' => 'client.ai_config.update',
        ]);

        $entry = $this->withToken($this->tokenFor($admin))->getJson('/api/admin/audit-log')->json('data.0');
        $this->assertSame($company->name, $entry['client']);
    }

    public function test_updating_a_connection_key_logs_the_action_but_never_the_key_itself(): void
    {
        $admin = $this->makeAdmin();
        $secret = 'sk-or-v1-super-secret-value';

        $this->withToken($this->tokenFor($admin))
            ->putJson('/api/admin/ai/connection', ['openrouter_api_key' => $secret])
            ->assertOk();

        $log = AuditLog::where('action', 'ai_connection.update')->firstOrFail();
        $this->assertStringNotContainsString($secret, $log->description);
        $this->assertStringNotContainsString($secret, json_encode($log->meta));
    }

    public function test_audit_log_requires_admin_auth(): void
    {
        $this->getJson('/api/admin/audit-log')->assertStatus(401);
    }
}
