<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Company;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The manual fallback for connecting a client's WhatsApp number from the
 * admin dashboard — ClientController::connectWhatsappAccount()/
 * disconnectWhatsappAccount(), for whenever Embedded Signup itself isn't
 * set up.
 */
class ClientWhatsappAccountTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return app(JwtService::class)->issue(AdminUser::factory()->create(), 'admin')['token'];
    }

    public function test_connects_a_whatsapp_number_to_a_client(): void
    {
        $company = Company::factory()->create();
        $token = $this->adminToken();

        $response = $this->withToken($token)->postJson("/api/admin/clients/{$company->id}/whatsapp-account", [
            'waba_id' => '1114448504487428',
            'phone_number_id' => '1218068078066046',
            'phone_number' => '+1 555 666 6063',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('whatsapp_accounts', [
            'company_id' => $company->id,
            'waba_id' => '1114448504487428',
            'phone_number_id' => '1218068078066046',
            'phone_number' => '+1 555 666 6063',
            'status' => 'connected',
        ]);
        $this->assertNotNull($company->whatsappAccounts()->first()->connected_at);
    }

    public function test_connecting_the_same_waba_again_updates_the_row_not_a_duplicate(): void
    {
        $company = Company::factory()->create();
        $token = $this->adminToken();

        $this->withToken($token)->postJson("/api/admin/clients/{$company->id}/whatsapp-account", [
            'waba_id' => 'waba-1', 'phone_number_id' => 'phone-old', 'phone_number' => '+1 111',
        ]);
        $this->withToken($token)->postJson("/api/admin/clients/{$company->id}/whatsapp-account", [
            'waba_id' => 'waba-1', 'phone_number_id' => 'phone-new', 'phone_number' => '+1 222',
        ]);

        $this->assertSame(1, $company->whatsappAccounts()->count());
        $this->assertDatabaseHas('whatsapp_accounts', ['waba_id' => 'waba-1', 'phone_number_id' => 'phone-new']);
    }

    public function test_connect_requires_waba_id_and_phone_number_id(): void
    {
        $company = Company::factory()->create();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/clients/{$company->id}/whatsapp-account", [])
            ->assertStatus(422);
    }

    public function test_connect_writes_an_audit_log_entry(): void
    {
        $company = Company::factory()->create();
        $admin = AdminUser::factory()->create();
        $token = app(JwtService::class)->issue($admin, 'admin')['token'];

        $this->withToken($token)->postJson("/api/admin/clients/{$company->id}/whatsapp-account", [
            'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1', 'phone_number' => '+1 111',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'client.whatsapp_account.connect',
            'company_id' => $company->id,
        ]);
    }

    public function test_disconnects_a_whatsapp_number(): void
    {
        $company = Company::factory()->create();
        $account = $company->whatsappAccounts()->create([
            'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1', 'status' => 'connected', 'connected_at' => now(),
        ]);

        $response = $this->withToken($this->adminToken())
            ->deleteJson("/api/admin/clients/{$company->id}/whatsapp-account/{$account->id}");

        $response->assertOk();
        $this->assertDatabaseHas('whatsapp_accounts', ['id' => $account->id, 'status' => 'disconnected']);
    }

    public function test_disconnecting_a_number_from_a_different_company_fails_cleanly(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $account = $otherCompany->whatsappAccounts()->create([
            'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1', 'status' => 'connected', 'connected_at' => now(),
        ]);

        $this->withToken($this->adminToken())
            ->deleteJson("/api/admin/clients/{$company->id}/whatsapp-account/{$account->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('whatsapp_accounts', ['id' => $account->id, 'status' => 'connected']); // untouched
    }

    public function test_connect_requires_admin_auth(): void
    {
        $company = Company::factory()->create();

        $this->postJson("/api/admin/clients/{$company->id}/whatsapp-account", [
            'waba_id' => 'waba-1', 'phone_number_id' => 'phone-1',
        ])->assertStatus(401);
    }
}
