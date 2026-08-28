<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppGatewayService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /v1/whatsapp/send — same relationship to WhatsAppGatewayService as
 * Gateway\AiController has to AiGatewayService.
 */
class WhatsAppController extends Controller
{
    public function __construct(private readonly WhatsAppGatewayService $whatsapp) {}

    public function send(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'category' => ['required', 'string', 'in:utility,authentication,marketing,service'],
            'country' => ['required', 'string', 'size:2'],
            'text' => ['required', 'string', 'max:4096'],
        ]);

        $company = $request->attributes->get('company');

        if (! $this->whatsapp->isEnabledFor($company)) {
            return response()->json(['error' => 'WhatsApp Gateway is not enabled for this account.'], 403);
        }

        try {
            $result = $this->whatsapp->send(
                $company,
                $data['to'],
                $data['category'],
                strtoupper($data['country']),
                ['type' => 'text', 'text' => ['body' => $data['text']]],
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'messages' => $result['messages'] ?? [],
            'remaining_balance' => (float) ($company->wallet->fresh()->balance ?? 0),
        ]);
    }
}
