<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JwtMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_token_is_rejected(): void
    {
        $this->getJson('/api/admin/me')->assertStatus(401);
    }

    public function test_malformed_token_is_rejected(): void
    {
        $this->withToken('not-a-real-jwt')->getJson('/api/admin/me')->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $admin = AdminUser::factory()->create();

        config(['jwt.ttl' => -5]); // issue a token that's already expired
        $token = app(JwtService::class)->issue($admin, 'admin')['token'];

        $this->withToken($token)->getJson('/api/admin/me')->assertStatus(401);
    }

    public function test_a_token_whose_subject_was_deleted_is_rejected(): void
    {
        $admin = AdminUser::factory()->create();
        $token = app(JwtService::class)->issue($admin, 'admin')['token'];
        $admin->delete();

        $this->withToken($token)->getJson('/api/admin/me')->assertStatus(401);
    }
}
