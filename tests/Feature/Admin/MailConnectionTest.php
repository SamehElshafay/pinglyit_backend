<?php

namespace Tests\Feature\Admin;

use App\Mail\MailConnectionTestMail;
use App\Models\AdminUser;
use App\Models\PlatformSetting;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = AdminUser::factory()->create();

        return app(JwtService::class)->issue($admin, 'admin')['token'];
    }

    public function test_show_reports_the_env_default_provider_when_nothing_is_saved(): void
    {
        $this->withToken($this->adminToken())->getJson('/api/admin/mail/connection')
            ->assertOk()
            ->assertJson(['provider' => config('mail.default')]);
    }

    public function test_update_can_switch_to_smtp_and_save_gmail_credentials(): void
    {
        $token = $this->adminToken();

        $response = $this->withToken($token)->putJson('/api/admin/mail/connection', [
            'provider' => 'smtp',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_username' => 'pingly514@gmail.com',
            'smtp_password' => 'an-app-password',
            'smtp_encryption' => 'tls',
        ]);

        $response->assertOk()->assertJson(['provider' => 'smtp', 'configured' => true]);
        $this->assertEquals('smtp.gmail.com', PlatformSetting::get('mail_smtp_host'));
        $this->assertEquals('smtp', PlatformSetting::get('mail_provider'));
    }

    public function test_update_never_returns_the_raw_smtp_password_or_resend_key(): void
    {
        $response = $this->withToken($this->adminToken())->putJson('/api/admin/mail/connection', [
            'smtp_password' => 'super-secret-app-password',
            'resend_api_key' => 're_super_secret_key_value',
        ]);

        $this->assertStringNotContainsString('super-secret-app-password', (string) $response->getContent());
        $this->assertStringNotContainsString('re_super_secret_key_value', (string) $response->getContent());
        $this->assertStringEndsWith('alue', $response->json('resend.preview'));
    }

    public function test_switching_provider_without_its_credentials_reports_not_configured(): void
    {
        // .env itself has no SMTP creds to fall back on here, forcing the
        // half-done state this asserts against.
        config(['mail.mailers.smtp.host' => null, 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null]);

        $response = $this->withToken($this->adminToken())->putJson('/api/admin/mail/connection', [
            'provider' => 'smtp',
        ]);

        // Picking smtp alone, with no host/username/password yet, is a
        // real half-done state — it must say so rather than claim ready.
        $response->assertOk()->assertJson(['provider' => 'smtp', 'configured' => false]);
    }

    public function test_update_with_no_fields_is_rejected(): void
    {
        $this->withToken($this->adminToken())->putJson('/api/admin/mail/connection', [])
            ->assertStatus(422);
    }

    public function test_destroy_clears_everything_and_falls_back_to_env(): void
    {
        $token = $this->adminToken();
        $this->withToken($token)->putJson('/api/admin/mail/connection', [
            'provider' => 'smtp',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_username' => 'pingly514@gmail.com',
            'smtp_password' => 'an-app-password',
        ]);

        $this->withToken($token)->deleteJson('/api/admin/mail/connection')
            ->assertOk()
            ->assertJson(['provider' => config('mail.default')]);

        $this->assertNull(PlatformSetting::get('mail_provider'));
        $this->assertNull(PlatformSetting::get('mail_smtp_host'));
    }

    public function test_connection_routes_require_admin_auth(): void
    {
        $this->getJson('/api/admin/mail/connection')->assertStatus(401);
    }

    public function test_test_endpoint_rejects_an_unconfigured_provider(): void
    {
        config(['mail.mailers.smtp.host' => null, 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null]);
        $token = $this->adminToken();
        $this->withToken($token)->putJson('/api/admin/mail/connection', ['provider' => 'smtp']);

        $this->withToken($token)->postJson('/api/admin/mail/connection/test', ['to' => 'admin@pingly.test'])
            ->assertStatus(422);
    }

    public function test_test_endpoint_sends_through_the_active_provider(): void
    {
        Mail::fake();
        $token = $this->adminToken();
        $this->withToken($token)->putJson('/api/admin/mail/connection', [
            'provider' => 'smtp',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_username' => 'pingly514@gmail.com',
            'smtp_password' => 'an-app-password',
        ]);

        $this->withToken($token)->postJson('/api/admin/mail/connection/test', ['to' => 'admin@pingly.test'])
            ->assertOk();

        Mail::assertSent(MailConnectionTestMail::class, fn ($mail) => $mail->hasTo('admin@pingly.test') && $mail->provider === 'smtp');
    }
}
