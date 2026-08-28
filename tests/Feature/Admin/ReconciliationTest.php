<?php

namespace Tests\Feature\Admin;

use App\Enums\ServiceType;
use App\Models\AdminUser;
use App\Models\BillingReconciliation;
use App\Models\Company;
use App\Models\UsageEvent;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a real bug: the original query used SQLite's
 * strftime() to group by month, which silently breaks on MySQL (the
 * production database). This must return valid JSON on whatever DB the
 * app is actually running on — the test suite runs on SQLite, production
 * runs on MySQL, and this endpoint has to work on both.
 */
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $admin = AdminUser::factory()->create();

        return app(JwtService::class)->issue($admin, 'admin')['token'];
    }

    public function test_it_returns_valid_totals_with_no_usage_at_all(): void
    {
        $this->withToken($this->adminToken())->getJson('/api/admin/billing/reconciliation')
            ->assertOk()
            ->assertJson(['real_openrouter_cost' => 0, 'billed_to_clients' => 0, 'margin_earned' => 0, 'periods' => []]);
    }

    public function test_it_groups_ai_usage_by_calendar_month_and_computes_margin(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 1000]);

        UsageEvent::factory()->count(3)->create([
            'company_id' => $company->id,
            'service_type' => ServiceType::Ai,
            'raw_cost_to_pingly' => 0.01,
            'billed_amount_to_client' => 0.05,
            'created_at' => now(),
        ]);
        // A WhatsApp event must never leak into the AI-only reconciliation totals.
        UsageEvent::factory()->create([
            'company_id' => $company->id,
            'service_type' => ServiceType::WhatsApp,
            'raw_cost_to_pingly' => 100,
            'billed_amount_to_client' => 125,
        ]);

        $response = $this->withToken($this->adminToken())->getJson('/api/admin/billing/reconciliation')->assertOk();

        // assertEqualsWithDelta, not assertJsonPath — summing 0.05 three times
        // in float arithmetic is 0.15000000000000002, not 0.15 exactly.
        $this->assertEqualsWithDelta(0.03, $response->json('real_openrouter_cost'), 0.0000001);
        $this->assertEqualsWithDelta(0.15, $response->json('billed_to_clients'), 0.0000001);
        $this->assertEqualsWithDelta(0.12, $response->json('margin_earned'), 0.0000001);
        $this->assertCount(1, $response->json('periods'));
        $this->assertEquals(now()->format('Y-m'), $response->json('periods.0.period'));
        $this->assertEquals('pending', $response->json('periods.0.status'));
    }

    public function test_a_reconciliation_run_surfaces_as_the_current_periods_status(): void
    {
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 1000]);
        UsageEvent::factory()->create([
            'company_id' => $company->id,
            'service_type' => ServiceType::Ai,
            'raw_cost_to_pingly' => 10,
            'billed_amount_to_client' => 50,
        ]);

        BillingReconciliation::create([
            'period' => now()->format('Y-m'),
            'internal_cost' => 10,
            'openrouter_reported_usage' => 10,
            'drift' => 0,
            'status' => 'matched',
            'note' => 'test run',
            'checked_at' => now(),
        ]);

        $response = $this->withToken($this->adminToken())->getJson('/api/admin/billing/reconciliation')->assertOk();

        $this->assertEquals('matched', $response->json('periods.0.status'));
        $this->assertEquals(10, $response->json('periods.0.invoiced'));
    }
}
