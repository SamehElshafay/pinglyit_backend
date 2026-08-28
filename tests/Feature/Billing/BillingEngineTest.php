<?php

namespace Tests\Feature\Billing;

use App\Enums\ServiceType;
use App\Models\AdminUser;
use App\Models\Company;
use App\Services\Billing\BillingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingEngineTest extends TestCase
{
    use RefreshDatabase;

    private function companyWithBalance(float $balance): Company
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => $balance, 'currency' => 'USD']);

        return $company;
    }

    public function test_record_usage_debits_the_wallet_and_logs_the_event(): void
    {
        $company = $this->companyWithBalance(100);
        $billing = app(BillingEngine::class);

        $event = $billing->recordUsage($company, ServiceType::Ai, 0.001, 0.50, 5, ['model' => 'test-model']);

        $this->assertEquals('99.5000', $company->wallet->fresh()->balance);
        $this->assertEquals('0.500000', $event->billed_amount_to_client);
        $this->assertFalse($event->metadata['billing_cap_triggered'] ?? false);
    }

    /**
     * The exact scenario a real pricing bug produced: two tiny messages
     * would otherwise have drained $300 from a wallet (see AiGatewayService's
     * docblock). The cap must catch a wildly-wrong billed amount here.
     */
    public function test_a_billed_amount_over_the_safety_cap_is_capped_not_charged_in_full(): void
    {
        $company = $this->companyWithBalance(1000);
        config(['pingly.max_billed_per_event' => 5.00]);
        $billing = app(BillingEngine::class);

        $event = $billing->recordUsage($company, ServiceType::Ai, 0.001, 300.00, 1, ['model' => 'test-model']);

        $this->assertEquals('995.0000', $company->wallet->fresh()->balance); // only $5 debited, not $300
        $this->assertEquals('5.000000', $event->billed_amount_to_client);
        $this->assertTrue($event->metadata['billing_cap_triggered']);
        $this->assertEquals(300.00, $event->metadata['uncapped_amount']);
    }

    public function test_the_safety_cap_can_be_disabled_by_setting_it_to_zero(): void
    {
        $company = $this->companyWithBalance(1000);
        config(['pingly.max_billed_per_event' => 0]);
        $billing = app(BillingEngine::class);

        $event = $billing->recordUsage($company, ServiceType::Ai, 0.001, 300.00, 1, []);

        $this->assertEquals('300.000000', $event->billed_amount_to_client);
    }

    public function test_adjust_wallet_credits_and_logs_who_and_why(): void
    {
        $company = $this->companyWithBalance(10);
        $admin = AdminUser::factory()->create();
        $billing = app(BillingEngine::class);

        $adjustment = $billing->adjustWallet($company, $admin, 50, 'Welcome credit');

        $this->assertEquals('60.0000', $company->wallet->fresh()->balance);
        $this->assertEquals($admin->id, $adjustment->admin_user_id);
        $this->assertEquals('Welcome credit', $adjustment->reason);
    }

    public function test_credit_topup_is_idempotent_on_provider_reference(): void
    {
        $company = $this->companyWithBalance(0);
        $billing = app(BillingEngine::class);

        $billing->creditTopup($company, 25, 'USD', 'tap', 'chg_123');
        $billing->creditTopup($company, 25, 'USD', 'tap', 'chg_123'); // webhook retry

        $this->assertEquals('25.0000', $company->wallet->fresh()->balance);
        $this->assertEquals(1, $company->walletTopups()->count());
    }
}
