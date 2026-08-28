<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use Illuminate\Http\Request;

class WhatsappLogController extends Controller
{
    public function index(Request $request)
    {
        $events = UsageEvent::with('company:id,name')
            ->where('service_type', ServiceType::WhatsApp)
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->whereHas('company', fn ($c) => $c->where('name', 'like', "%{$s}%")))
            ->when($request->integer('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->latest()
            ->paginate(25);

        return response()->json($events->through(fn (UsageEvent $e) => [
            'time' => $e->created_at,
            'client' => $e->company->name,
            'category' => $e->metadata['category'] ?? null,
            'country' => $e->metadata['country'] ?? null,
            'cost' => (float) $e->raw_cost_to_pingly,
            'price' => (float) $e->billed_amount_to_client,
            'capped' => (bool) ($e->metadata['billing_cap_triggered'] ?? false),
        ]));
    }
}
