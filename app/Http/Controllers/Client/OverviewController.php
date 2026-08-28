<?php

namespace App\Http\Controllers\Client;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OverviewController extends Controller
{
    public function index(Request $request)
    {
        $company = $request->user()->company;
        $periodStart = Carbon::now()->subDays(30);
        $events = $company->usageEvents()->where('created_at', '>=', $periodStart);

        return response()->json([
            'wallet_balance' => (float) ($company->wallet->balance ?? 0),
            // Both counted the same way now — "how many requests", not one
            // in requests and the other in dollars.
            'whatsapp_requests_this_period' => (clone $events)->where('service_type', ServiceType::WhatsApp)->count(),
            'ai_requests_this_period' => (clone $events)->where('service_type', ServiceType::Ai)->count(),
            'daily_usage' => $this->dailyUsage($company->id),
            'recent_activity' => $company->usageEvents()->latest()->limit(10)->get()
                ->map(fn ($e) => [
                    'time' => $e->created_at,
                    'event' => $e->service_type === ServiceType::WhatsApp ? 'WhatsApp message sent' : 'AI request',
                ]),
        ]);
    }

    /**
     * 14 days, zero-filled (a day with no requests must still be a point on
     * the chart, not a gap) — one row per day with both series' counts.
     *
     * @return array<int, array{date: string, whatsapp: int, ai: int}>
     */
    private function dailyUsage(int $companyId): array
    {
        $since = Carbon::now()->subDays(13)->startOfDay();

        $rows = DB::table('usage_events')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as date, service_type, COUNT(*) as requests')
            ->groupBy('date', 'service_type')
            ->get()
            ->groupBy('date');

        return collect(range(13, 0))->map(function (int $daysAgo) use ($rows) {
            $date = Carbon::now()->subDays($daysAgo)->toDateString();
            $dayRows = $rows->get($date, collect());

            return [
                'date' => $date,
                'whatsapp' => (int) optional($dayRows->firstWhere('service_type', 'whatsapp'))->requests,
                'ai' => (int) optional($dayRows->firstWhere('service_type', 'ai'))->requests,
            ];
        })->values()->all();
    }
}
