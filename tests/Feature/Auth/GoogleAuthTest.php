<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/**
 * Real RSA-signed tokens, not a shortcut around verification: this
 * generates its own keypair, serves it as the JWKS response, and signs
 * test ID tokens with it — the same shape GoogleAuthService::verify()
 * actually has to handle for a real Google-issued token, just with a key
 * this test controls instead of Google's.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = 'test-client-id.apps.googleusercontent.com';

    private OpenSSLAsymmetricKey $privateKey;

    private string $privateKeyPem;

    protected function setUp(): void
    {
        parent::setUp();

        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $pem = '';
        openssl_pkey_export($resource, $pem);
        $this->privateKeyPem = $pem;
        $this->privateKey = $resource;

        $details = openssl_pkey_get_details($resource);
        $jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'test-kid',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->base64url($details['rsa']['n']),
            'e' => $this->base64url($details['rsa']['e']),
        ]]];
        Http::fake(['*googleapis.com/oauth2/v3/certs*' => Http::response($jwks)]);

        PlatformSetting::set('google_oauth_client_id', self::CLIENT_ID);
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function idToken(array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'google-sub-123456',
            'email' => 'newperson@gmail.com',
            'email_verified' => true,
            'name' => 'New Person',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides);

        return JWT::encode($claims, $this->privateKeyPem, 'RS256', 'test-kid');
    }

    public function test_config_reports_unconfigured_when_no_client_id_is_set(): void
    {
        PlatformSetting::set('google_oauth_client_id', null);

        $this->getJson('/api/auth/google/config')
            ->assertOk()
            ->assertJson(['configured' => false, 'client_id' => null]);
    }

    public function test_config_reports_the_client_id_once_set(): void
    {
        $this->getJson('/api/auth/google/config')
            ->assertOk()
            ->assertJson(['configured' => true, 'client_id' => self::CLIENT_ID]);
    }

    public function test_a_brand_new_google_signin_creates_a_company_wallet_and_user(): void
    {
        $response = $this->postJson('/api/auth/google', ['credential' => $this->idToken()]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'company_id']]);
        $response->assertJsonPath('user.email', 'newperson@gmail.com');

        $user = User::where('email', 'newperson@gmail.com')->firstOrFail();
        $this->assertSame('google-sub-123456', $user->google_id);
        $this->assertNotNull($user->company);
        $this->assertNotNull($user->company->wallet);
        $this->assertEquals('0.0000', $user->company->wallet->balance);
    }

    public function test_a_google_signin_matching_an_existing_email_logs_into_that_account(): void
    {
        $company = Company::factory()->create();
        $existing = User::factory()->create(['company_id' => $company->id, 'email' => 'already-here@gmail.com']);

        $response = $this->postJson('/api/auth/google', [
            'credential' => $this->idToken(['email' => 'already-here@gmail.com']),
        ]);

        $response->assertOk()->assertJsonPath('user.id', $existing->id);
        $this->assertSame(1, User::where('email', 'already-here@gmail.com')->count()); // no duplicate created
        $this->assertSame('google-sub-123456', $existing->fresh()->google_id); // linked
    }

    public function test_rejects_a_token_with_the_wrong_audience(): void
    {
        $this->postJson('/api/auth/google', [
            'credential' => $this->idToken(['aud' => 'someone-elses-client-id']),
        ])->assertStatus(422);
    }

    public function test_rejects_an_unverified_email(): void
    {
        $this->postJson('/api/auth/google', [
            'credential' => $this->idToken(['email_verified' => false]),
        ])->assertStatus(422);
    }

    public function test_rejects_a_token_signed_by_a_different_key(): void
    {
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($otherKey, $otherKeyPem);

        $forged = JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'attacker',
            'email' => 'attacker@gmail.com',
            'email_verified' => true,
            'iat' => time(),
            'exp' => time() + 3600,
        ], $otherKeyPem, 'RS256', 'test-kid'); // same kid, different actual key

        $this->postJson('/api/auth/google', ['credential' => $forged])->assertStatus(422);
    }

    public function test_fails_cleanly_when_not_configured(): void
    {
        PlatformSetting::set('google_oauth_client_id', null);

        $this->postJson('/api/auth/google', ['credential' => 'irrelevant'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Google sign-in is not configured — add the Client ID in the admin dashboard first.']);
    }
}
