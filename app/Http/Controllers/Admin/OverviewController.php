<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use App\Models\Wallet;
use App\Models\WalletAdjustment;
use Illuminate\Support\Carbon;

class OverviewController extends Controller
{
    public function index()
    {
        $periodStart = Carbon::now()->subDays(30);

        $usageThisPeriod = UsageEvent::where('created_at', '>=', $periodStart);

        return response()->json([
            'wallet_balance_held' => (float) Wallet::sum('balance'),
            'revenue_this_period' => (float) (clone $usageThisPeriod)->sum('billed_amount_to_client')
                - (float) (clone $usageThisPeriod)->sum('raw_cost_to_pingly'),
            'whatsapp_messages_this_period' => (clone $usageThisPeriod)->where('service_type', ServiceType::WhatsApp)->count(),
            'ai_tokens_billed_this_period' => (float) (clone $usageThisPeriod)->where('service_type', ServiceType::Ai)
                ->get()->sum(fn ($event) => (float) ($event->metadata['billed_tokens'] ?? 0)),
            // Starting slice — a real activity feed would also union signups
            // and config changes once the audit log (screen-map: /audit) exists.
            'recent_activity' => WalletAdjustment::with('company:id,name', 'admin:id,name')
                ->latest()->limit(10)->get()
                ->map(fn ($a) => [
                    'time' => $a->created_at,
                    'event' => $a->amount >= 0 ? 'Wallet credited' : 'Wallet debited',
                    'client' => $a->company->name,
                ]),
        ]);
    }
}
