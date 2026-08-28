<?php

namespace Database\Factories;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageEvent>
 */
class UsageEventFactory extends Factory
{
    protected $model = UsageEvent::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'service_type' => ServiceType::Ai,
            'raw_cost_to_pingly' => 0.01,
            'billed_amount_to_client' => 0.05,
            'multiplier_or_margin_applied' => 5,
            'metadata' => ['model' => 'openai/gpt-4o-mini'],
        ];
    }
}
