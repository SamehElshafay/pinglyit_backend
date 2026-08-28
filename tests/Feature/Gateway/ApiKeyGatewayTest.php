<?php

namespace Tests\Feature\Gateway;

use App\Models\ApiKey;
use App\Models\Company;
use App\Services\Ai\AiGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards /v1/* — the pk_live_ key a client's own project calls with. This
 * is a completely separate auth path from the JWT the dashboards use.
 */
class ApiKeyGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_key_is_rejected(): void
    {
        $this->getJson('/api/v1/balance')->assertStatus(401);
    }

    public function test_malformed_key_is_rejected(): void
    {
        $this->withToken('not-a-pk-live-key')->getJson('/api/v1/balance')->assertStatus(401);
    }

    public function test_unknown_key_is_rejected(): void
    {
        $this->withToken('pk_live_'.str_repeat('x', 32))->getJson('/api/v1/balance')->assertStatus(401);
    }

    public function test_a_revoked_key_is_rejected(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 10]);
        [$key, $plaintext] = ApiKey::generate($company, 'Production');
        $key->update(['revoked_at' => now()]);

        $this->withToken($plaintext)->getJson('/api/v1/balance')->assertStatus(401);
    }

    public function test_a_valid_key_returns_that_companys_own_balance(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 42.5, 'currency' => 'USD']);
        [, $plaintext] = ApiKey::generate($company, 'Production');

        $this->withToken($plaintext)->getJson('/api/v1/balance')
            ->assertOk()
            ->assertJson(['balance' => 42.5, 'currency' => 'USD']);
    }

    public function test_a_valid_key_only_ever_sees_its_own_companys_balance(): void
    {
        $companyA = Company::factory()->create();
        $companyA->wallet()->create(['balance' => 5]);
        [, $keyA] = ApiKey::generate($companyA, 'A');

        $companyB = Company::factory()->create();
        $companyB->wallet()->create(['balance' => 999]);
        ApiKey::generate($companyB, 'B');

        $this->withToken($keyA)->getJson('/api/v1/balance')
            ->assertOk()
            ->assertJson(['balance' => 5]);
    }

    public function test_ai_chat_is_rejected_when_the_service_is_disabled_for_the_company(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 10]);
        [, $plaintext] = ApiKey::generate($company, 'Production');
        app(AiGatewayService::class)->setEnabledFor($company, false);

        $this->withToken($plaintext)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ])->assertStatus(403);
    }

    public function test_ai_chat_fails_cleanly_when_openrouter_is_not_configured(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 10]);
        [, $plaintext] = ApiKey::generate($company, 'Production');

        $response = $this->withToken($plaintext)->postJson('/api/v1/ai/chat', [
            'model' => 'openai/gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ]);

        // Never a 500 — a clean, explained failure (AiGatewayService::forward's RuntimeException).
        $response->assertStatus(422)->assertJsonStructure(['error']);
    }
}
