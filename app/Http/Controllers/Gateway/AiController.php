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

    public function chat(Request $request)
    {
        $data = $request->validate([
            'model' => ['required', 'string'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant'],
            'messages.*.content' => ['required', 'string'],
        ]);

        $company = $request->attributes->get('company');

        if (! $this->ai->isEnabledFor($company)) {
            return response()->json(['error' => 'AI Gateway is not enabled for this account.'], 403);
        }

        try {
            $result = $this->ai->forward($company, $data['model'], $data['messages']);
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
