<?php

namespace Tests\Feature\Console;

use App\Enums\ServiceType;
use App\Models\BillingReconciliation;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileAiBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_unavailable_when_openrouter_is_not_configured(): void
    {
        $this->artisan('pingly:reconcile-ai-billing')->assertSuccessful();

        $run = BillingReconciliation::firstOrFail();
        $this->assertEquals('unavailable', $run->status);
        $this->assertNull($run->openrouter_reported_usage);
    }

    public function test_it_records_matched_when_totals_agree_within_tolerance(): void
    {
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test-key');
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 100]);
        UsageEvent::factory()->create([
            'company_id' => $company->id,
            'service_type' => ServiceType::Ai,
            'raw_cost_to_pingly' => 12.345,
        ]);

        Http::fake(['*/credits' => Http::response(['data' => ['total_usage' => 12.345]])]);

        $this->artisan('pingly:reconcile-ai-billing')->assertSuccessful();

        $run = BillingReconciliation::firstOrFail();
        $this->assertEquals('matched', $run->status);
        $this->assertEquals(0, (float) $run->drift);
    }

    public function test_it_records_drifted_when_totals_disagree_beyond_tolerance(): void
    {
        PlatformSetting::set('openrouter_api_key', 'sk-or-v1-test-key');
        $company = Company::factory()->create();
        $company->wallet()->create(['balance' => 100]);
        UsageEvent::factory()->create([
            'company_id' => $company->id,
            'service_type' => ServiceType::Ai,
            'raw_cost_to_pingly' => 10.00,
        ]);

        Http::fake(['*/credits' => Http::response(['data' => ['total_usage' => 25.00]])]);

        $this->artisan('pingly:reconcile-ai-billing')->assertSuccessful();

        $run = BillingReconciliation::firstOrFail();
        $this->assertEquals('drifted', $run->status);
        $this->assertEquals(15.0, (float) $run->drift);
    }

    public function test_running_it_twice_in_the_same_month_updates_the_one_row_not_duplicates(): void
    {
        $this->artisan('pingly:reconcile-ai-billing');
        $this->artisan('pingly:reconcile-ai-billing');

        $this->assertEquals(1, BillingReconciliation::count());
    }
}
