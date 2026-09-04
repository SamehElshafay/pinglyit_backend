<?php

namespace Tests\Feature\Auth;

use App\Mail\WelcomeMail;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_company_a_user_and_an_empty_wallet(): void
    {
        $response = $this->postJson('/api/register', [
            'company_name' => 'Acme Inc.',
            'email' => 'founder@acme.test',
            'password' => 'password123',
        ]);

        $response->assertCreated()->assertJsonStructure(['token', 'user', 'company']);

        $company = Company::where('contact_email', 'founder@acme.test')->firstOrFail();
        $this->assertNotNull($company->wallet);
        $this->assertSame('0.0000', $company->wallet->balance);
        $this->assertDatabaseHas('users', ['email' => 'founder@acme.test', 'company_id' => $company->id]);
    }

    public function test_register_sends_a_welcome_email(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'company_name' => 'Acme Inc.',
            'email' => 'founder@acme.test',
            'password' => 'password123',
        ])->assertCreated();

        Mail::assertSent(WelcomeMail::class, function (WelcomeMail $mail) {
            return $mail->hasTo('founder@acme.test') && $mail->user->company?->name === 'Acme Inc.';
        });
    }

    public function test_login_does_not_send_a_welcome_email(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test', 'password' => 'password']);

        $this->postJson('/api/login', ['email' => 'demo@acme.test', 'password' => 'password'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        Company::factory()->create(['contact_email' => 'founder@acme.test']);

        $this->postJson('/api/register', [
            'company_name' => 'Another Co.',
            'email' => 'founder@acme.test',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        $company = Company::factory()->create();
        User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test', 'password' => 'password']);

        $this->postJson('/api/login', ['email' => 'demo@acme.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'company_id']]);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $company = Company::factory()->create();
        User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test', 'password' => 'password']);

        $this->postJson('/api/login', ['email' => 'demo@acme.test', 'password' => 'nope'])
            ->assertStatus(422);
    }

    public function test_an_admin_token_cannot_access_client_routes(): void
    {
        $admin = AdminUser::factory()->create();
        $token = app(JwtService::class)->issue($admin, 'admin')['token'];

        $this->withToken($token)->getJson('/api/me')->assertStatus(403);
    }
}
