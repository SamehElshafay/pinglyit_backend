<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\PlatformSetting;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers both AiConnectionController and PaymentConnectionController —
 * same PlatformSetting-backed pattern, so one parameterized-by-hand test
 * class exercises both instead of duplicating it.
 */
class PlatformSettingConnectionTest extends TestCase
{
    use RefreshDatabase;

    public static function connections(): array
    {
        return [
            'OpenRouter' => ['/api/admin/ai/connection', 'openrouter_api_key', 'sk-or-v1-abcdefghijklmnop'],
            'Tap Payments' => ['/api/admin/payment/connection', 'tap_secret_key', 'sk_test_abcdefghijklmnop'],
        ];
    }

    private function adminToken(): string
    {
        $admin = AdminUser::factory()->create();

        return app(JwtService::class)->issue($admin, 'admin')['token'];
    }

    #[DataProvider('connections')]
    public function test_show_reports_not_configured_when_nothing_is_set(string $endpoint): void
    {
        $this->withToken($this->adminToken())->getJson($endpoint)
            ->assertOk()
            ->assertJson(['configured' => false, 'preview' => null]);
    }

    #[DataProvider('connections')]
    public function test_update_stores_the_key_encrypted_and_never_returns_it_raw(string $endpoint, string $field, string $key): void
    {
        $response = $this->withToken($this->adminToken())
            ->putJson($endpoint, [$field => $key]);

        $response->assertOk()->assertJson(['configured' => true]);
        $this->assertStringEndsWith(substr($key, -4), $response->json('preview'));
        $this->assertStringNotContainsString($key, (string) $response->getContent());

        // The raw plaintext must never sit in the database column either —
        // PlatformSetting's 'encrypted' cast should have transformed it.
        $raw = DB::table('platform_settings')->where('key', $field)->value('value');
        $this->assertStringNotContainsString($key, $raw);
        $this->assertEquals($key, PlatformSetting::get($field));
    }

    #[DataProvider('connections')]
    public function test_update_rejects_a_too_short_key(string $endpoint, string $field): void
    {
        $this->withToken($this->adminToken())
            ->putJson($endpoint, [$field => 'x'])
            ->assertStatus(422);
    }

    #[DataProvider('connections')]
    public function test_destroy_clears_the_key(string $endpoint, string $field, string $key): void
    {
        $token = $this->adminToken();
        $this->withToken($token)->putJson($endpoint, [$field => $key]);

        $this->withToken($token)->deleteJson($endpoint)
            ->assertOk()
            ->assertJson(['configured' => false, 'preview' => null]);
        $this->assertNull(PlatformSetting::get($field));
    }

    #[DataProvider('connections')]
    public function test_connection_routes_require_admin_auth(string $endpoint): void
    {
        $this->getJson($endpoint)->assertStatus(401);
    }
}
