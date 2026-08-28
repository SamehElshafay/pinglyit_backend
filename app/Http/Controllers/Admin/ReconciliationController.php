<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use Illuminate\Support\Carbon;

class ReconciliationController extends Controller
{
    /**
     * "matched" vs. Pingly's actual OpenRouter invoice is a scheduled job
     * this doesn't run yet (docs §4.5) — every period reports as 'pending'
     * until that reconciliation job exists and writes a real invoice total.
     * The real_cost / margin numbers themselves are already accurate, since
     * they come straight from UsageEvent.
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

        $periods = UsageEvent::where('service_type', ServiceType::Ai)
            ->selectRaw("strftime('%Y-%m', created_at) as period, sum(raw_cost_to_pingly) as real_cost")
            ->groupBy('period')
            ->orderByDesc('period')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'realCost' => (float) $row->real_cost,
                'invoiced' => null,
                'status' => 'pending',
            ]);

        return response()->json([
            'real_openrouter_cost' => $totals['real_cost'],
            'billed_to_clients' => $totals['billed'],
            'margin_earned' => $totals['billed'] - $totals['real_cost'],
            'periods' => $periods,
        ]);
    }
}
