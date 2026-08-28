<?php

namespace App\Console\Commands;

use App\Enums\ServiceType;
use App\Models\BillingReconciliation;
use App\Models\UsageEvent;
use App\Services\Ai\AiGatewayService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily sanity check: does Pingly's own recorded AI Gateway spend match what
 * OpenRouter itself says was spent on this key? Scheduled in routes/console.php.
 *
 * OpenRouter's /credits endpoint only reports a *lifetime* running total —
 * there's no historical per-month breakdown to check a closed calendar
 * month against. So this writes one row per calendar month (updateOrCreate
 * on the current month, run daily), but the comparison itself is always
 * "running total vs. running total as of right now" — which only really
 * means something for the *current*, still-open period. Past months keep
 * whatever status they had the last time they were "current"; a period
 * that closed before this job ever ran stays 'pending' in the UI, honestly,
 * because there's nothing to check it against.
 */
class ReconcileAiBilling extends Command
{
    protected $signature = 'pingly:reconcile-ai-billing';

    protected $description = "Compare Pingly's recorded AI Gateway spend against OpenRouter's own lifetime usage total";

    public function handle(AiGatewayService $ai): int
    {
        $period = Carbon::now()->format('Y-m');
        $internalCost = (float) UsageEvent::where('service_type', ServiceType::Ai)->sum('raw_cost_to_pingly');
        $reported = $ai->accountUsage();

        if ($reported === null) {
            BillingReconciliation::updateOrCreate(
                ['period' => $period],
                [
                    'internal_cost' => $internalCost,
                    'openrouter_reported_usage' => null,
                    'drift' => null,
                    'status' => 'unavailable',
                    'note' => 'OpenRouter is not connected, or the /credits lookup failed — check the log.',
                    'checked_at' => now(),
                ],
            );

            $this->warn('OpenRouter usage unavailable — recorded as unavailable, not matched.');

            return self::SUCCESS;
        }

        $drift = round(abs($internalCost - $reported), 6);
        $tolerance = 0.01; // a cent — OpenRouter rounds its own total slightly differently than we accumulate ours

        BillingReconciliation::updateOrCreate(
            ['period' => $period],
            [
                'internal_cost' => $internalCost,
                'openrouter_reported_usage' => $reported,
                'drift' => $drift,
                'status' => $drift <= $tolerance ? 'matched' : 'drifted',
                'note' => 'Lifetime cumulative check — OpenRouter reports a running total, not per-month, so this compares running totals as of the check time.',
                'checked_at' => now(),
            ],
        );

        $this->info("Reconciled: internal=\${$internalCost} openrouter=\${$reported} drift=\${$drift}");

        return self::SUCCESS;
    }
}
