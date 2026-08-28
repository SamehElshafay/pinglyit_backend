<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_reset_email_for_a_real_account(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test']);

        $response = $this->postJson('/api/forgot-password', ['email' => 'demo@acme.test']);

        $response->assertOk();
        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo($user->email));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'demo@acme.test']);
    }

    public function test_forgot_password_never_reveals_whether_the_account_exists(): void
    {
        Mail::fake();

        $known = $this->postJson('/api/forgot-password', ['email' => 'nobody@nowhere.test']);

        $known->assertOk();
        Mail::assertNothingSent();
    }

    public function test_reset_password_with_a_valid_token_updates_the_password(): void
    {
        Mail::fake();
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test', 'password' => 'old-password']);

        $this->postJson('/api/forgot-password', ['email' => 'demo@acme.test']);

        $capturedUrl = null;
        Mail::assertSent(PasswordResetMail::class, function ($mail) use (&$capturedUrl) {
            $capturedUrl = $mail->resetUrl;

            return true;
        });
        parse_str(parse_url($capturedUrl, PHP_URL_QUERY), $query);
        $token = $query['token'];

        $this->postJson('/api/reset-password', [
            'email' => 'demo@acme.test',
            'token' => $token,
            'password' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'demo@acme.test']);

        // The now-used token can't be replayed.
        $this->postJson('/api/reset-password', [
            'email' => 'demo@acme.test',
            'token' => $token,
            'password' => 'another-password',
        ])->assertStatus(422);
    }

    public function test_reset_password_with_a_wrong_token_is_rejected(): void
    {
        $company = Company::factory()->create();
        User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test']);

        DB::table('password_reset_tokens')->insert([
            'email' => 'demo@acme.test',
            'token' => Hash::make('the-real-token'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => 'demo@acme.test',
            'token' => 'a-completely-wrong-token',
            'password' => 'brand-new-password',
        ])->assertStatus(422);
    }

    public function test_reset_password_with_an_expired_token_is_rejected(): void
    {
        $company = Company::factory()->create();
        User::factory()->create(['company_id' => $company->id, 'email' => 'demo@acme.test']);

        DB::table('password_reset_tokens')->insert([
            'email' => 'demo@acme.test',
            'token' => Hash::make('the-real-token'),
            'created_at' => now()->subHours(2),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => 'demo@acme.test',
            'token' => 'the-real-token',
            'password' => 'brand-new-password',
        ])->assertStatus(422);
    }
}
