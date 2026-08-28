<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\Ai\AiGatewayService;
use App\Services\Billing\ServiceConfigRepository;
use App\Services\WhatsApp\WhatsAppGatewayService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(
        private readonly WhatsAppGatewayService $whatsapp,
        private readonly AiGatewayService $ai,
        private readonly ServiceConfigRepository $configs,
    ) {}

    public function index(Request $request)
    {
        $companies = Company::query()
            ->with('wallet')
            ->when($request->string('search')->toString(), fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->latest()
            ->paginate(20);

        return response()->json($companies->through(fn (Company $company) => [
            'id' => $company->id,
            'name' => $company->name,
            'status' => $company->status,
            'balance' => (float) ($company->wallet->balance ?? 0),
            'services' => array_values(array_filter([
                $this->whatsapp->isEnabledFor($company) ? 'WhatsApp' : null,
                $this->ai->isEnabledFor($company) ? 'AI' : null,
            ])),
            'joined' => $company->created_at,
        ]));
    }

    public function show(Company $company)
    {
        $company->load('wallet', 'whatsappAccounts');

        return response()->json([
            'id' => $company->id,
            'name' => $company->name,
            'email' => $company->contact_email,
            'status' => $company->status,
            'joined' => $company->created_at,
            'balance' => (float) ($company->wallet->balance ?? 0),
            'whatsapp' => [
                'enabled' => $this->whatsapp->isEnabledFor($company),
                'pricing' => $this->whatsapp->pricingFor($company),
                'accounts' => $company->whatsappAccounts,
            ],
            'ai' => [
                'enabled' => $this->ai->isEnabledFor($company),
                'pricing' => $this->ai->pricingFor($company),
            ],
        ]);
    }

    public function updateWhatsappConfig(Request $request, Company $company)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'margin_percent' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'monthly_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        if (array_key_exists('enabled', $data)) {
            $this->whatsapp->setEnabledFor($company, $data['enabled']);
        }

        $override = array_diff_key($data, ['enabled' => null]);
        if ($override !== []) {
            $this->configs->setClientOverride($company, ServiceType::WhatsApp, $override);
        }

        if ($data !== []) {
            AuditLog::record(
                $request->user(),
                'client.whatsapp_config.update',
                $this->describeClientConfigChange('WhatsApp Gateway', $data),
                $company,
                $data,
            );
        }

        return response()->json(['whatsapp' => [
            'enabled' => $this->whatsapp->isEnabledFor($company),
            'pricing' => $this->whatsapp->pricingFor($company),
        ]]);
    }

    public function updateAiConfig(Request $request, Company $company)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'multiplier' => ['sometimes', 'nullable', 'numeric', 'min:1'],
        ]);

        if (array_key_exists('enabled', $data)) {
            $this->ai->setEnabledFor($company, $data['enabled']);
        }

        $override = array_diff_key($data, ['enabled' => null]);
        if ($override !== []) {
            $this->configs->setClientOverride($company, ServiceType::Ai, $override);
        }

        if ($data !== []) {
            AuditLog::record(
                $request->user(),
                'client.ai_config.update',
                $this->describeClientConfigChange('AI Gateway', $data),
                $company,
                $data,
            );
        }

        return response()->json(['ai' => [
            'enabled' => $this->ai->isEnabledFor($company),
            'pricing' => $this->ai->pricingFor($company),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $data  validated request data (only the keys actually sent)
     */
    private function describeClientConfigChange(string $service, array $data): string
    {
        $parts = [];

        if (array_key_exists('enabled', $data)) {
            $parts[] = $data['enabled'] ? 'enabled' : 'disabled';
        }
        foreach (array_diff_key($data, ['enabled' => null]) as $key => $value) {
            $parts[] = "{$key} → {$value}";
        }

        return "{$service}: ".implode(', ', $parts);
    }

    /**
     * Read-only — admin can see that a key exists and its label, never the
     * key itself (only the client ever saw the plaintext, at creation).
     */
    public function apiKeys(Company $company)
    {
        return response()->json(
            $company->apiKeys()->whereNull('revoked_at')->latest()->get()
                ->map(fn ($k) => ['id' => $k->id, 'label' => $k->label, 'prefix' => $k->key_prefix.'…', 'created' => $k->created_at])
        );
    }
}
