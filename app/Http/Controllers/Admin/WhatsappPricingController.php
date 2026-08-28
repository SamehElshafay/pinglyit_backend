<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Http\Request;

class WhatsappPricingController extends Controller
{
    public function __construct(private readonly ServiceConfigRepository $configs) {}

    public function show()
    {
        return response()->json($this->configs->platformDefault(ServiceType::WhatsApp));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'margin_percent' => ['required', 'numeric', 'min:0'],
            'monthly_fee' => ['required', 'numeric', 'min:0'],
            'base_costs' => ['sometimes', 'array'],
            'base_costs.*.category' => ['required_with:base_costs', 'string'],
            'base_costs.*.country' => ['required_with:base_costs', 'string'],
            'base_costs.*.cost' => ['required_with:base_costs', 'numeric', 'min:0'],
        ]);

        $existing = $this->configs->platformDefault(ServiceType::WhatsApp);
        $config = $this->configs->setPlatformDefault(ServiceType::WhatsApp, array_merge($existing, $data));

        AuditLog::record(
            $request->user(),
            'whatsapp_pricing.update',
            "Set WhatsApp Gateway margin to {$data['margin_percent']}% and monthly fee to \${$data['monthly_fee']}",
            meta: $data,
        );

        return response()->json($config->pricing_config);
    }
}
