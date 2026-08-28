<?php

namespace Database\Seeders;

use App\Enums\ServiceType;
use App\Models\AdminUser;
use App\Models\ApiKey;
use App\Models\Company;
use App\Models\UsageEvent;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = AdminUser::firstOrCreate(
            ['email' => 'admin@pingly.test'],
            ['name' => 'Pingly Admin', 'password' => 'password'],
        );

        $configs = app(ServiceConfigRepository::class);

        $configs->setPlatformDefault(ServiceType::WhatsApp, [
            'margin_percent' => 25,
            'monthly_fee' => 15,
            'base_costs' => [
                ['category' => 'utility', 'country' => 'EG', 'cost' => 0.0300],
                ['category' => 'authentication', 'country' => 'EG', 'cost' => 0.0135],
                ['category' => 'marketing', 'country' => 'EG', 'cost' => 0.0400],
                ['category' => 'service', 'country' => 'EG', 'cost' => 0.0000],
            ],
        ]);

        $configs->setPlatformDefault(ServiceType::Ai, [
            'multiplier' => 5, // fallback for any model not listed below
            'model_overrides' => [
                'openai/gpt-4o' => 3,
                'openai/gpt-4o-mini' => 8,
                'mistralai/mistral-nemo' => 10, // cheapest on OpenRouter — high multiplier still nets pennies for the client
                'meta-llama/llama-3.1-8b-instruct' => 10,
            ],
        ]);

        if (Company::where('contact_email', 'demo@acme.test')->doesntExist()) {
            $company = Company::create(['name' => 'Acme Inc.', 'contact_email' => 'demo@acme.test']);
            $wallet = $company->wallet()->create(['balance' => 250, 'currency' => 'USD']);

            $company->users()->create([
                'name' => 'Acme Demo User',
                'email' => 'demo@acme.test',
                'password' => 'password',
            ]);

            $company->whatsappAccounts()->create([
                'phone_number' => '+20 100 000 0000',
                'status' => 'connected',
                'connected_at' => now()->subDays(10),
            ]);

            ApiKey::generate($company, 'Production');

            $billing = app(BillingEngine::class);
            $billing->recordUsage($company, ServiceType::WhatsApp, 0.0300, 0.0375, 25, ['category' => 'utility', 'country' => 'EG']);
            $billing->recordUsage($company, ServiceType::WhatsApp, 0.0135, 0.0169, 25, ['category' => 'authentication', 'country' => 'EG']);
            $billing->recordUsage($company, ServiceType::Ai, 0.0021, 0.0105, 5, ['model' => 'openai/gpt-4o-mini', 'real_tokens' => 1400, 'billed_tokens' => 7000]);
            $billing->adjustWallet($company, $admin, 100, 'Welcome credit');

            $this->spreadDemoUsageOverDays($company);
        }

        $this->command?->info('Admin login: admin@pingly.test / password');
        $this->command?->info('Demo client login: demo@acme.test / password');
    }

    /**
     * Backdated usage events across the last 14 days, so the client
     * dashboard's daily trend chart has an actual trend to show instead of
     * one spike on seed day. Written directly (not via BillingEngine) so
     * seeding stays idempotent-ish and doesn't re-touch the wallet balance
     * every time this runs.
     */
    private function spreadDemoUsageOverDays(Company $company): void
    {
        // [days ago => [whatsapp count, ai count]] — a rough "ramping up" shape.
        $pattern = [13 => [1, 0], 11 => [2, 1], 9 => [0, 2], 8 => [3, 1], 6 => [1, 3], 5 => [2, 2], 3 => [4, 3], 2 => [2, 5], 1 => [3, 4]];

        foreach ($pattern as $daysAgo => [$whatsappCount, $aiCount]) {
            $when = Carbon::now()->subDays($daysAgo)->setTime(random_int(9, 17), random_int(0, 59));

            for ($i = 0; $i < $whatsappCount; $i++) {
                UsageEvent::create([
                    'company_id' => $company->id,
                    'service_type' => ServiceType::WhatsApp,
                    'raw_cost_to_pingly' => 0.0300,
                    'billed_amount_to_client' => 0.0375,
                    'multiplier_or_margin_applied' => 25,
                    'metadata' => ['category' => 'utility', 'country' => 'EG'],
                    'created_at' => $when,
                    'updated_at' => $when,
                ]);
            }

            for ($i = 0; $i < $aiCount; $i++) {
                UsageEvent::create([
                    'company_id' => $company->id,
                    'service_type' => ServiceType::Ai,
                    'raw_cost_to_pingly' => 0.0021,
                    'billed_amount_to_client' => 0.0105,
                    'multiplier_or_margin_applied' => 5,
                    'metadata' => ['model' => 'openai/gpt-4o-mini', 'real_tokens' => 1400, 'billed_tokens' => 7000],
                    'created_at' => $when,
                    'updated_at' => $when,
                ]);
            }
        }
    }
}
