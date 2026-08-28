<?php

namespace Database\Seeders;

use App\Enums\ServiceType;
use App\Models\AdminUser;
use App\Models\ApiKey;
use App\Models\Company;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
            ],
            'token_to_currency_rate' => 1.0,
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
        }

        $this->command?->info('Admin login: admin@pingly.test / password');
        $this->command?->info('Demo client login: demo@acme.test / password');
    }
}
