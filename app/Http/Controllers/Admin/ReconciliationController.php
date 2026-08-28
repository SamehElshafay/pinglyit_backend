<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\BillingReconciliation;
use App\Models\UsageEvent;
use Illuminate\Support\Carbon;

class ReconciliationController extends Controller
{
    /**
     * "matched" vs. Pingly's actual OpenRouter spend comes from the daily
     * ReconcileAiBilling job (routes/console.php), which writes one row per
     * month to billing_reconciliations. OpenRouter's own API only reports a
     * lifetime running total (no historical per-month breakdown), so in
     * practice only the *current* period ever carries a real matched/drifted
     * status — closed months stay 'pending', honestly, since there's nothing
     * to check them against after the fact. The real_cost / margin numbers
     * themselves are always accurate regardless, since they come straight
     * from UsageEvent.
     */
    public function index()
    {
        $periodStart = Carbon::now()->subDays(30);

        $totals = [
            'real_cost' => (float) UsageEvent::where('service_type', ServiceType::Ai)
                ->where('created_at', '>=', $periodStart)->sum('raw_cost_to_pingly'),
            'billed' => (float) UsageEvent::where('service_type', ServiceType::Ai)
                ->where('created_at', '>=', $periodStart)->sum('billed_amount_to_client'),
        ];

        // Grouped here in PHP rather than with a driver-specific SQL date
        // function (e.g. MySQL's DATE_FORMAT vs. SQLite's strftime) — this
        // stays correct on both the MySQL used in production and the SQLite
        // in-memory DB the test suite runs against. Bounded to ~13 months so
        // this doesn't scan the whole table forever as usage grows.
        $monthlyRealCost = UsageEvent::where('service_type', ServiceType::Ai)
            ->where('created_at', '>=', Carbon::now()->subMonths(13)->startOfMonth())
            ->get(['created_at', 'raw_cost_to_pingly'])
            ->groupBy(fn (UsageEvent $event) => $event->created_at->format('Y-m'))
            ->map(fn ($group) => (float) $group->sum('raw_cost_to_pingly'));

        $reconciliations = BillingReconciliation::query()->get()->keyBy('period');

        $periods = $monthlyRealCost->sortKeysDesc()->take(12)
            ->map(function (float $realCost, string $period) use ($reconciliations) {
                $run = $reconciliations->get($period);

                return [
                    'period' => $period,
                    'realCost' => $realCost,
                    'invoiced' => $run?->openrouter_reported_usage !== null ? (float) $run->openrouter_reported_usage : null,
                    'status' => $run->status ?? 'pending',
                    'note' => $run?->note,
                    'checkedAt' => $run?->checked_at,
                ];
            })
            ->values();

        return response()->json([
            'real_openrouter_cost' => $totals['real_cost'],
            'billed_to_clients' => $totals['billed'],
            'margin_earned' => $totals['billed'] - $totals['real_cost'],
            'periods' => $periods,
        ]);
    }
}
