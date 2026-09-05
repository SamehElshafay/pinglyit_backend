<?php

namespace Tests\Feature\Commerce;

use App\Models\ApiKey;
use App\Models\Company;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders are read-only from these endpoints — they only ever get created by
 * AiCommerceAgentService's create_order tool during a real WhatsApp
 * conversation (see AiCommerceAgentTest). updateStatus is the one write —
 * a human override for the rare case the AI's own tool calls didn't cover.
 */
class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function clientToken(Company $company): string
    {
        $user = $company->users()->create(['name' => 'Demo', 'email' => 'demo@example.test', 'password' => 'password']);

        return app(JwtService::class)->issue($user, 'user')['token'];
    }

    private function makeOrder(Company $company, array $overrides = []): \App\Models\Order
    {
        return $company->orders()->create(array_merge([
            'customer_phone' => '201555555555', 'status' => 'pending_confirmation', 'total' => 50, 'currency' => 'USD',
        ], $overrides));
    }

    public function test_dashboard_lists_only_its_own_orders(): void
    {
        $company = Company::factory()->create();
        $this->makeOrder($company);
        $this->makeOrder(Company::factory()->create()); // someone else's

        $this->withToken($this->clientToken($company))->getJson('/api/orders')
            ->assertOk()->assertJsonCount(1);
    }

    public function test_dashboard_can_filter_orders_by_status(): void
    {
        $company = Company::factory()->create();
        $this->makeOrder($company, ['status' => 'pending_confirmation']);
        $this->makeOrder($company, ['status' => 'confirmed']);

        $this->withToken($this->clientToken($company))->getJson('/api/orders?status=confirmed')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.status', 'confirmed');
    }

    public function test_a_company_cannot_view_or_update_another_companys_order(): void
    {
        $owner = Company::factory()->create();
        $order = $this->makeOrder($owner);

        $intruder = Company::factory()->create();
        $token = $this->clientToken($intruder);

        $this->withToken($token)->getJson("/api/orders/{$order->id}")->assertStatus(403);
        $this->withToken($token)->putJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(403);
    }

    public function test_manual_status_override_only_works_on_a_pending_order(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrder($company, ['status' => 'confirmed']);
        $token = $this->clientToken($company);

        $this->withToken($token)->putJson("/api/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertStatus(422);
    }

    public function test_manual_status_override_confirms_a_pending_order(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrder($company);
        $token = $this->clientToken($company);

        $this->withToken($token)->putJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertNotNull($order->fresh()->confirmed_at);
    }

    public function test_gateway_api_key_can_read_its_own_orders(): void
    {
        $company = Company::factory()->create();
        $this->makeOrder($company);
        [, $plaintext] = ApiKey::generate($company, 'Production');

        $this->withToken($plaintext)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1);
    }
}
