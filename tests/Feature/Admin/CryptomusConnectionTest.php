<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\PlatformSetting;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CryptomusConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = AdminUser::factory()->create();

        return app(JwtService::class)->issue($admin, 'admin')['token'];
    }

    public function test_show_reports_not_configured_when_nothing_is_set(): void
    {
        $this->withToken($this->adminToken())->getJson('/api/admin/cryptomus/connection')
            ->assertOk()
            ->assertJson(['configured' => false]);
    }

    public function test_update_can_set_one_field_at_a_time_without_touching_the_other(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->putJson('/api/admin/cryptomus/connection', ['cryptomus_merchant_id' => 'merchant-uuid-1234'])
            ->assertOk()
            ->assertJson(['configured' => false, 'merchant_id' => 'merchant-uuid-1234']); // still missing the api key

        $response = $this->withToken($token)->putJson('/api/admin/cryptomus/connection', ['cryptomus_api_key' => 'a-real-payment-api-key']);

        $response->assertOk()->assertJson(['configured' => true, 'merchant_id' => 'merchant-uuid-1234']);
        $this->assertStringEndsWith('-key', $response->json('api_key.preview'));
        $this->assertEquals('merchant-uuid-1234', PlatformSetting::get('cryptomus_merchant_id'));
        $this->assertEquals('a-real-payment-api-key', PlatformSetting::get('cryptomus_api_key'));
    }

    public function test_update_never_returns_the_raw_api_key(): void
    {
        $response = $this->withToken($this->adminToken())
            ->putJson('/api/admin/cryptomus/connection', ['cryptomus_api_key' => 'super_secret_key_value']);

        $this->assertStringNotContainsString('super_secret_key_value', (string) $response->getContent());
    }

    public function test_update_with_no_fields_is_rejected(): void
    {
        $this->withToken($this->adminToken())
            ->putJson('/api/admin/cryptomus/connection', [])
            ->assertStatus(422);
    }

    public function test_destroy_clears_both_fields(): void
    {
        $token = $this->adminToken();
        $this->withToken($token)->putJson('/api/admin/cryptomus/connection', [
            'cryptomus_merchant_id' => 'merchant-uuid-1234',
            'cryptomus_api_key' => 'a-real-payment-api-key',
        ]);

        $this->withToken($token)->deleteJson('/api/admin/cryptomus/connection')
            ->assertOk()
            ->assertJson(['configured' => false]);

        $this->assertNull(PlatformSetting::get('cryptomus_merchant_id'));
        $this->assertNull(PlatformSetting::get('cryptomus_api_key'));
    }

    public function test_connection_routes_require_admin_auth(): void
    {
        $this->getJson('/api/admin/cryptomus/connection')->assertStatus(401);
    }
}
