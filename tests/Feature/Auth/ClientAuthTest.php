<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpMail;
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

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Inc.',
            'email' => 'founder@acme.test',
            'password' => 'password123',
            'terms_accepted' => true,
        ], $overrides);
    }

    public function test_register_creates_a_company_and_an_unverified_user_but_no_token_yet(): void
    {
        $response = $this->postJson('/api/register', $this->registerPayload());

        $response->assertCreated()->assertJson(['email' => 'founder@acme.test']);
        $response->assertJsonMissing(['token']); // not usable until VerifyOtpController::verify()

        $company = Company::where('contact_email', 'founder@acme.test')->firstOrFail();
        $this->assertNotNull($company->wallet);
        $this->assertSame('0.0000', $company->wallet->balance);

        $user = User::where('email', 'founder@acme.test')->firstOrFail();
        $this->assertSame($company->id, $user->company_id);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at); // a real, timestamped consent record
    }

    public function test_register_requires_accepting_the_terms(): void
    {
        $this->postJson('/api/register', $this->registerPayload(['terms_accepted' => false]))
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'founder@acme.test']);
    }

    public function test_register_sends_an_otp_not_a_welcome_email(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        Mail::assertSent(OtpMail::class, fn (OtpMail $mail) => $mail->hasTo('founder@acme.test'));
        Mail::assertNotSent(WelcomeMail::class); // that's VerifyOtpController::verify()'s job, once the code is actually entered
    }

    public function test_verifying_the_right_otp_completes_signup_and_sends_the_welcome_email(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $otp = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $response = $this->postJson('/api/verify-otp', ['email' => 'founder@acme.test', 'otp' => $otp]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'company_id'], 'company']);
        $this->assertNotNull(User::where('email', 'founder@acme.test')->first()->email_verified_at);
        Mail::assertSent(WelcomeMail::class, fn (WelcomeMail $mail) => $mail->hasTo('founder@acme.test'));
    }

    public function test_verifying_the_wrong_otp_is_rejected_and_leaves_the_account_unverified(): void
    {
        Mail::fake();

        $this->postJson('/api/register', $this->registerPayload())->assertCreated();

        $this->postJson('/api/verify-otp', ['email' => 'founder@acme.test', 'otp' => '000000'])
            ->assertStatus(422);

        $this->assertNull(User::where('email', 'founder@acme.test')->first()->email_verified_at);
    }

    public function test_resend_otp_issues_a_fresh_code_for_an_unverified_account(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        User::factory()->unverified()->create(['company_id' => $company->id, 'email' => 'demo@acme.test']);

        $this->postJson('/api/resend-otp', ['email' => 'demo@acme.test'])->assertOk();

        Mail::assertSent(OtpMail::class, fn (OtpMail $mail) => $mail->hasTo('demo@acme.test'));
    }

    public function test_resend_otp_sends_nothing_for_an_already_verified_account(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test']); // verified by default

        $this->postJson('/api/resend-otp', ['email' => 'demo@acme.test'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_login_to_an_unverified_account_sends_a_new_code_instead_of_a_token(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        User::factory()->unverified()->create(['company_id' => $company->id, 'email' => 'demo@acme.test', 'password' => 'password']);

        $response = $this->postJson('/api/login', ['email' => 'demo@acme.test', 'password' => 'password']);

        $response->assertStatus(403)->assertJson(['verification_required' => true, 'email' => 'demo@acme.test']);
        $response->assertJsonMissing(['token']);
        Mail::assertSent(OtpMail::class, fn (OtpMail $mail) => $mail->hasTo('demo@acme.test'));
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
