<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\PlatformSetting;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymobConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = AdminUser::factory()->create();

        return app(JwtService::class)->issue($admin, 'admin')['token'];
    }

    public function test_show_reports_not_configured_when_nothing_is_set(): void
    {
        $this->withToken($this->adminToken())->getJson('/api/admin/paymob/connection')
            ->assertOk()
            ->assertJson(['configured' => false]);
    }

    public function test_update_can_set_one_key_at_a_time_without_touching_the_others(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->putJson('/api/admin/paymob/connection', ['paymob_public_key' => 'pk_test_abcdef'])
            ->assertOk()
            ->assertJson(['configured' => false]); // still missing secret + hmac + integration_id + rate

        $response = $this->withToken($token)->putJson('/api/admin/paymob/connection', [
            'paymob_secret_key' => 'sk_test_abcdef',
            'paymob_hmac_secret' => 'hmac_test_abcdef',
            'paymob_integration_id' => '987654',
            'paymob_usd_to_egp_rate' => '49.5',
        ]);

        $response->assertOk()->assertJson(['configured' => true, 'integration_id' => '987654', 'usd_to_egp_rate' => 49.5]);
        $this->assertStringEndsWith('cdef', $response->json('public_key.preview'));
        $this->assertEquals('pk_test_abcdef', PlatformSetting::get('paymob_public_key'));
        $this->assertEquals('sk_test_abcdef', PlatformSetting::get('paymob_secret_key'));
    }

    public function test_update_never_returns_a_raw_key(): void
    {
        $response = $this->withToken($this->adminToken())
            ->putJson('/api/admin/paymob/connection', ['paymob_secret_key' => 'sk_test_super_secret_value']);

        $this->assertStringNotContainsString('sk_test_super_secret_value', (string) $response->getContent());
    }

    public function test_update_with_no_fields_is_rejected(): void
    {
        $this->withToken($this->adminToken())
            ->putJson('/api/admin/paymob/connection', [])
            ->assertStatus(422);
    }

    public function test_destroy_clears_all_keys(): void
    {
        $token = $this->adminToken();
        $this->withToken($token)->putJson('/api/admin/paymob/connection', [
            'paymob_public_key' => 'pk_test_abcdef',
            'paymob_secret_key' => 'sk_test_abcdef',
            'paymob_hmac_secret' => 'hmac_test_abcdef',
            'paymob_integration_id' => '987654',
            'paymob_usd_to_egp_rate' => '49.5',
        ]);

        $this->withToken($token)->deleteJson('/api/admin/paymob/connection')
            ->assertOk()
            ->assertJson(['configured' => false]);

        $this->assertNull(PlatformSetting::get('paymob_public_key'));
        $this->assertNull(PlatformSetting::get('paymob_secret_key'));
        $this->assertNull(PlatformSetting::get('paymob_hmac_secret'));
        $this->assertNull(PlatformSetting::get('paymob_integration_id'));
        $this->assertNull(PlatformSetting::get('paymob_usd_to_egp_rate'));
    }

    public function test_update_rejects_an_invalid_rate(): void
    {
        $this->withToken($this->adminToken())
            ->putJson('/api/admin/paymob/connection', ['paymob_usd_to_egp_rate' => 'not-a-number'])
            ->assertStatus(422);
    }

    public function test_connection_routes_require_admin_auth(): void
    {
        $this->getJson('/api/admin/paymob/connection')->assertStatus(401);
    }

    public function test_active_gateway_endpoint_reports_the_configured_gateway(): void
    {
        config(['pingly.payment_gateway' => 'paymob']);

        $this->withToken($this->adminToken())->getJson('/api/admin/payment/active-gateway')
            ->assertOk()
            ->assertJson(['gateway' => 'paymob']);
    }
}
