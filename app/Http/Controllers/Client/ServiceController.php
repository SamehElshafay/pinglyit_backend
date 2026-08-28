<?php

namespace App\Http\Controllers\Client;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Services\Ai\AiGatewayService;
use App\Services\WhatsApp\WhatsAppGatewayService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(
        private readonly WhatsAppGatewayService $whatsapp,
        private readonly AiGatewayService $ai,
    ) {}

    public function index(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            ['key' => 'whatsapp', 'name' => ServiceType::WhatsApp->label(), 'enabled' => $this->whatsapp->isEnabledFor($company)],
            ['key' => 'ai', 'name' => ServiceType::Ai->label(), 'enabled' => $this->ai->isEnabledFor($company)],
        ]);
    }

    public function whatsapp(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            'connected' => $company->whatsappAccounts()->where('status', 'connected')->exists(),
            'accounts' => $company->whatsappAccounts,
            'usage' => $company->usageEvents()->where('service_type', ServiceType::WhatsApp)->latest()->limit(25)->get()
                ->map(fn ($e) => [
                    'time' => $e->created_at,
                    'category' => $e->metadata['category'] ?? null,
                    'country' => $e->metadata['country'] ?? null,
                    'cost' => (float) $e->billed_amount_to_client,
                ]),
        ]);
    }

    public function ai(Request $request)
    {
        $company = $request->user()->company;

        // Deliberately no multiplier here — the client sees tokens/credits
        // used and their balance, never the internal rate they're billed at.
        return response()->json([
            'balance' => (float) ($company->wallet->balance ?? 0),
            'usage' => $company->usageEvents()->where('service_type', ServiceType::Ai)->latest()->limit(25)->get()
                ->map(fn ($e) => [
                    'time' => $e->created_at,
                    'model' => $e->metadata['model'] ?? null,
                    'tokens' => $e->metadata['billed_tokens'] ?? null,
                ]),
        ]);
    }

    /**
     * The models this client can actually pick — whatever the admin has
     * priced for them, not the full OpenRouter catalog. Powers the model
     * Select on the dashboard's "try a request" box.
     */
    public function aiModels(Request $request)
    {
        return response()->json($this->ai->availableModelsFor($request->user()->company));
    }

    /**
     * Send one WhatsApp message through the gateway — this is the actual
     * "use the service" endpoint, as opposed to whatsapp() above which
     * just reports on past usage.
     */
    public function sendWhatsapp(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'category' => ['required', 'string', 'in:utility,authentication,marketing,service'],
            'country' => ['required', 'string', 'size:2'],
            'text' => ['required', 'string', 'max:4096'],
        ]);

        $company = $request->user()->company;

        if (! $this->whatsapp->isEnabledFor($company)) {
            return response()->json(['message' => 'WhatsApp Gateway is not enabled for this account.'], 403);
        }

        try {
            $result = $this->whatsapp->send(
                $company,
                $data['to'],
                $data['category'],
                strtoupper($data['country']),
                ['type' => 'text', 'text' => ['body' => $data['text']]],
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * Send one AI Gateway request — same relationship to ai() above.
     */
    public function chatAi(Request $request)
    {
        $data = $request->validate([
            'model' => ['required', 'string'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant'],
            'messages.*.content' => ['required', 'string'],
        ]);

        $company = $request->user()->company;

        if (! $this->ai->isEnabledFor($company)) {
            return response()->json(['message' => 'AI Gateway is not enabled for this account.'], 403);
        }

        try {
            $result = $this->ai->forward($company, $data['model'], $data['messages']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
