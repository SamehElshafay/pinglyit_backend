<?php

namespace Tests\Feature\Client;

use App\Models\Company;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTopupTest extends TestCase
{
    use RefreshDatabase;

    private function clientToken(Company $company): string
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        return app(JwtService::class)->issue($user, 'user')['token'];
    }

    public function test_topup_fails_cleanly_with_a_501_when_the_gateway_is_not_configured(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        $response = $this->withToken($this->clientToken($company))
            ->postJson('/api/wallet/topup', ['amount' => 25]);

        // Never a 500 — a clean, explained failure (TapGateway::createTopupSession's RuntimeException).
        $response->assertStatus(501)->assertJsonStructure(['message']);
    }

    public function test_topup_rejects_an_invalid_amount(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 0]);

        $this->withToken($this->clientToken($company))
            ->postJson('/api/wallet/topup', ['amount' => -5])
            ->assertStatus(422);
    }
}
