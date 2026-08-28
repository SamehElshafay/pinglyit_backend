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

        return response()->json([
            'multiplier' => $this->ai->multiplierFor($company),
            'usage' => $company->usageEvents()->where('service_type', ServiceType::Ai)->latest()->limit(25)->get()
                ->map(fn ($e) => [
                    'time' => $e->created_at,
                    'model' => $e->metadata['model'] ?? null,
                    'tokens' => $e->metadata['billed_tokens'] ?? null,
                ]),
        ]);
    }
}
