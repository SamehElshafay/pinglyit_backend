<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use Illuminate\Http\Request;

class AiLogController extends Controller
{
    public function index(Request $request)
    {
        $events = UsageEvent::with('company:id,name')
            ->where('service_type', ServiceType::Ai)
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->whereHas('company', fn ($c) => $c->where('name', 'like', "%{$s}%")))
            ->when($request->integer('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->latest()
            ->paginate(25);

        return response()->json($events->through(fn (UsageEvent $e) => [
            'time' => $e->created_at,
            'client' => $e->company->name,
            'realTokens' => $e->metadata['real_tokens'] ?? null,
            'realCost' => (float) $e->raw_cost_to_pingly,
            'multiplier' => (float) $e->multiplier_or_margin_applied,
            'billedTokens' => $e->metadata['billed_tokens'] ?? null,
            'billed' => (float) $e->billed_amount_to_client,
            'capped' => (bool) ($e->metadata['billing_cap_triggered'] ?? false),
            'uncappedAmount' => $e->metadata['uncapped_amount'] ?? null,
        ]));
    }
}
