<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin: set a client's balance to an exact amount, and delete a client.
 */
class ClientWalletAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return app(JwtService::class)->issue(AdminUser::factory()->create(), 'admin')['token'];
    }

    private function companyWithBalance(float $balance): Company
    {
        $company = Company::factory()->create();
        $company->wallet()->updateOrCreate([], ['balance' => $balance]);

        return $company;
    }

    public function test_sets_the_balance_to_an_exact_amount_and_records_the_difference(): void
    {
        $company = $this->companyWithBalance(7.08);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/clients/{$company->id}/wallet-adjustments", ['set_balance' => 5.08, 'reason' => 'Correction'])
            ->assertCreated();

        $this->assertEquals(5.08, (float) $company->wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_adjustments', ['company_id' => $company->id, 'amount' => -2]);
    }

    public function test_setting_the_same_balance_is_rejected(): void
    {
        $company = $this->companyWithBalance(3);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/clients/{$company->id}/wallet-adjustments", ['set_balance' => 3, 'reason' => 'Nothing'])
            ->assertStatus(422)->assertJsonValidationErrors('set_balance');
    }

    public function test_amount_and_set_balance_cannot_be_sent_together(): void
    {
        $company = $this->companyWithBalance(3);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/clients/{$company->id}/wallet-adjustments", ['amount' => 1, 'set_balance' => 9, 'reason' => 'Both'])
            ->assertStatus(422);
        $this->assertEquals(3, (float) $company->wallet->fresh()->balance);
    }

    public function test_deleting_a_client_needs_its_exact_name(): void
    {
        $company = Company::factory()->create(['name' => 'Acme']);

        $this->withToken($this->adminToken())
            ->deleteJson("/api/admin/clients/{$company->id}", ['confirm_name' => 'acme inc'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_name');
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_deletes_a_client_with_its_wallet_and_users_and_audits_it(): void
    {
        $company = $this->companyWithBalance(12.5);
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->withToken($this->adminToken())
            ->deleteJson("/api/admin/clients/{$company->id}", ['confirm_name' => $company->name])
            ->assertNoContent();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        $this->assertDatabaseMissing('wallets', ['company_id' => $company->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.destroy']);
    }
}
