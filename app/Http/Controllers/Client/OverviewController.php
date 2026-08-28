<?php

namespace App\Http\Controllers\Client;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OverviewController extends Controller
{
    public function index(Request $request)
    {
        $company = $request->user()->company;
        $periodStart = Carbon::now()->subDays(30);
        $events = $company->usageEvents()->where('created_at', '>=', $periodStart);

        return response()->json([
            'wallet_balance' => (float) ($company->wallet->balance ?? 0),
            'whatsapp_usage_this_period' => (clone $events)->where('service_type', ServiceType::WhatsApp)->count(),
            'ai_usage_this_period' => (float) (clone $events)->where('service_type', ServiceType::Ai)->sum('billed_amount_to_client'),
            'recent_activity' => $company->usageEvents()->latest()->limit(10)->get()
                ->map(fn ($e) => [
                    'time' => $e->created_at,
                    'event' => $e->service_type === ServiceType::WhatsApp ? 'WhatsApp message sent' : 'AI request',
                ]),
        ]);
    }
}
