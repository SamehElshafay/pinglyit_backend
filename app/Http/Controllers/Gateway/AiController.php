<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiGatewayService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /v1/ai/chat — this is the actual product: the client's own project
 * calls this with its pk_live_ key. Never exposes the multiplier — only
 * the completion plus what it left in the wallet.
 */
class AiController extends Controller
{
    public function __construct(private readonly AiGatewayService $ai) {}

    /** GET /v1/models — what this key's company is priced for and can call. */
    public function models(Request $request)
    {
        return response()->json($this->ai->availableModelsFor($request->attributes->get('company')));
    }

    public function chat(Request $request)
    {
        $data = $request->validate([
            'model' => ['required', 'string'],
            'messages' => ['required', 'array', 'min:1'],
            // 'tool' role + tool_call_id/tool_calls/name cover sending a tool's
            // result back in a follow-up call — see the class docblock.
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['nullable', 'string'],
            'messages.*.tool_calls' => ['sometimes', 'array'],
            'messages.*.tool_call_id' => ['sometimes', 'string'],
            'messages.*.name' => ['sometimes', 'string'],
            'tools' => ['sometimes', 'array'],
            'tool_choice' => ['sometimes'],
        ]);

        $company = $request->attributes->get('company');

        if (! $this->ai->isEnabledFor($company)) {
            return response()->json(['error' => 'AI Gateway is not enabled for this account.'], 403);
        }

        try {
            $result = $this->ai->forward($company, $data['model'], $data['messages'], [
                'tools' => $data['tools'] ?? null,
                'tool_choice' => $data['tool_choice'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $result['id'] ?? null,
            'model' => $result['model'] ?? $data['model'],
            'choices' => $result['choices'] ?? [],
            'remaining_balance' => (float) ($company->wallet->fresh()->balance ?? 0),
        ]);
    }
}
