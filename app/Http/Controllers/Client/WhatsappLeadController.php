<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\WhatsappLead;
use App\Models\WhatsappMessage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Sales Agent's pipeline: every customer it has talked to, how likely
 * they are to buy, and what to do next — plus taking a conversation over
 * from the AI and handing it back.
 */
class WhatsappLeadController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'temperature' => ['nullable', Rule::in(WhatsappLead::TEMPERATURES)],
            'stage' => ['nullable', Rule::in(WhatsappLead::STAGES)],
            'needs_human' => ['nullable', 'boolean'],
        ]);
        $company = $request->user()->company;

        $leads = WhatsappLead::where('company_id', $company->id)
            ->when($filters['temperature'] ?? null, fn ($q, $t) => $q->where('temperature', $t))
            ->when($filters['stage'] ?? null, fn ($q, $s) => $q->where('stage', $s))
            ->when($request->boolean('needs_human'), fn ($q) => $q->where('ai_paused', true))
            ->orderByRaw("case temperature when 'hot' then 0 when 'warm' then 1 when 'cold' then 2 else 3 end")
            ->orderByDesc('last_message_at')
            ->limit(200)
            ->get();

        $lastMessages = WhatsappMessage::forCompany($company->id)
            ->whereIn('customer_phone', $leads->pluck('customer_phone'))
            ->whereIn('id', fn ($q) => $q->selectRaw('max(id)')->from('whatsapp_messages')
                ->where('company_id', $company->id)->groupBy('customer_phone'))
            ->get()
            ->keyBy('customer_phone');

        $all = WhatsappLead::where('company_id', $company->id);

        return response()->json([
            'counts' => [
                'hot' => (clone $all)->where('temperature', 'hot')->count(),
                'warm' => (clone $all)->where('temperature', 'warm')->count(),
                'cold' => (clone $all)->where('temperature', 'cold')->count(),
                'time_waster' => (clone $all)->where('temperature', 'time_waster')->count(),
                'needs_human' => (clone $all)->where('ai_paused', true)->count(),
                'won' => (clone $all)->where('stage', 'won')->count(),
            ],
            'leads' => $leads->map(fn (WhatsappLead $l) => [
                ...$l->only(['id', 'customer_phone', 'customer_name', 'stage', 'temperature', 'intent_score', 'buyer_style',
                    'summary', 'needs', 'objections', 'next_action', 'ai_paused', 'handoff_reason', 'messages_count',
                    'last_message_at', 'assessed_at']),
                'last_message' => ($m = $lastMessages->get($l->customer_phone)) ? [
                    'body' => $m->body, 'direction' => $m->direction, 'time' => $m->created_at,
                ] : null,
            ]),
        ]);
    }

    /** Take over from the AI, or hand back to it. */
    public function update(Request $request, int $lead)
    {
        $data = $request->validate([
            'ai_paused' => ['sometimes', 'boolean'],
            'stage' => ['sometimes', Rule::in(WhatsappLead::STAGES)],
        ]);

        $lead = WhatsappLead::where('company_id', $request->user()->company_id)->findOrFail($lead);

        if (array_key_exists('ai_paused', $data)) {
            $data['handoff_reason'] = $data['ai_paused'] ? 'Taken over from the dashboard.' : null;
        }
        $lead->update($data);

        return response()->json($lead->fresh());
    }
}
