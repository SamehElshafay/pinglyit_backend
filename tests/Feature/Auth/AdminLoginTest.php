<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        AdminUser::factory()->create(['email' => 'admin@pingly.test', 'password' => 'password']);

        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin@pingly.test',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'admin' => ['id', 'name', 'email']]);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        AdminUser::factory()->create(['email' => 'admin@pingly.test', 'password' => 'password']);

        $response = $this->postJson('/api/admin/login', [
            'email' => 'admin@pingly.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_with_unknown_email_is_rejected(): void
    {
        $response = $this->postJson('/api/admin/login', [
            'email' => 'nobody@pingly.test',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/admin/me')->assertStatus(401);
    }

    public function test_me_returns_the_logged_in_admin(): void
    {
        AdminUser::factory()->create(['email' => 'admin@pingly.test', 'password' => 'password']);
        $token = $this->postJson('/api/admin/login', ['email' => 'admin@pingly.test', 'password' => 'password'])->json('token');

        $this->withToken($token)->getJson('/api/admin/me')
            ->assertOk()
            ->assertJsonPath('admin.email', 'admin@pingly.test');
    }

    public function test_logout_revokes_the_token(): void
    {
        AdminUser::factory()->create(['email' => 'admin@pingly.test', 'password' => 'password']);
        $token = $this->postJson('/api/admin/login', ['email' => 'admin@pingly.test', 'password' => 'password'])->json('token');

        $this->withToken($token)->postJson('/api/admin/logout')->assertNoContent();

        // Same token, reused after logout — must be rejected, not just client-discarded.
        $this->withToken($token)->getJson('/api/admin/me')->assertStatus(401);
    }

    public function test_a_client_token_cannot_access_admin_routes(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = app(JwtService::class)->issue($user, 'user')['token'];

        $this->withToken($token)->getJson('/api/admin/me')->assertStatus(403);
    }
}
