<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ThrowingTransport;
use Tests\TestCase;

/**
 * What every auth flow does when the mail transport itself refuses the
 * send — the one case the rest of the auth suite can't cover, because it
 * uses Mail::fake() and a fake never fails. Production did fail, and each
 * of these flows answered with a 500.
 */
class MailFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Points the default mailer at a transport that throws on every send.
     */
    private function breakTheMailer(): void
    {
        Mail::extend('throwing', fn () => new ThrowingTransport);

        config([
            'mail.mailers.throwing' => ['transport' => 'throwing'],
            'mail.default' => 'throwing',
        ]);
    }

    public function test_forgot_password_answers_identically_whether_or_not_the_mail_goes_out(): void
    {
        $this->breakTheMailer();
        $company = Company::factory()->create();
        User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test']);

        $existing = $this->postJson('/api/forgot-password', ['email' => 'demo@acme.test']);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@nowhere.test']);

        // The whole point: a failed send must not make these two
        // distinguishable, or the endpoint becomes an account-existence
        // oracle for anyone watching status codes.
        $existing->assertOk();
        $unknown->assertOk();
        $this->assertSame($existing->json('message'), $unknown->json('message'));

        // The token is still issued, so the link works once mail is fixed
        // and the person asks again.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'demo@acme.test']);
    }

    public function test_register_rolls_the_signup_back_when_the_otp_cannot_be_sent(): void
    {
        $this->breakTheMailer();

        $response = $this->postJson('/api/register', [
            'company_name' => 'Acme Inc.',
            'email' => 'founder@acme.test',
            'password' => 'password123',
            'terms_accepted' => true,
        ]);

        $response->assertStatus(502);

        // Nothing half-created: an account kept here would be unreachable
        // forever — unverified so it can't log in, taken so it can't sign
        // up again. Rolling back leaves a signup they can just retry.
        $this->assertDatabaseMissing('users', ['email' => 'founder@acme.test']);
        $this->assertDatabaseMissing('companies', ['contact_email' => 'founder@acme.test']);
        $this->assertDatabaseMissing('email_otps', ['email' => 'founder@acme.test']);
    }

    public function test_verifying_an_otp_still_completes_signup_when_the_welcome_email_fails(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email' => 'founder@acme.test',
            'email_verified_at' => null,
        ]);

        // Stand in for a code issued while mail was still working.
        DB::table('email_otps')->insert([
            'email' => $user->email,
            'otp' => Hash::make('123456'),
            'attempts' => 0,
            'created_at' => now(),
        ]);

        $this->breakTheMailer();

        $response = $this->postJson('/api/verify-otp', [
            'email' => 'founder@acme.test',
            'otp' => '123456',
        ]);

        // The code was right; a welcome email that won't send is no reason
        // to withhold the token the person just earned.
        $response->assertOk();
        $response->assertJsonStructure(['token', 'user', 'company']);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
