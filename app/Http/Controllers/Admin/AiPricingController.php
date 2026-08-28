<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Ai\AiGatewayService;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Http\Request;

class AiPricingController extends Controller
{
    public function __construct(
        private readonly ServiceConfigRepository $configs,
        private readonly AiGatewayService $ai,
    ) {}

    /** Starting menu for the "add a per-model override" picker. */
    public function models()
    {
        return response()->json($this->ai->suggestedModels());
    }

    public function show()
    {
        $config = $this->configs->platformDefault(ServiceType::Ai);

        return response()->json(array_merge([
            'multiplier' => config('pingly.ai.default_multiplier'),
            'model_overrides' => [],
        ], $config));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'multiplier' => ['required', 'numeric', 'min:1'],
            'model_overrides' => ['sometimes', 'array'],
            'model_overrides.*' => ['numeric', 'min:1'],
        ]);

        $existing = $this->configs->platformDefault(ServiceType::Ai);
        $config = $this->configs->setPlatformDefault(ServiceType::Ai, array_merge($existing, $data));

        AuditLog::record(
            $request->user(),
            'ai_pricing.update',
            "Set AI Gateway fallback multiplier to {$data['multiplier']}x".(isset($data['model_overrides']) ? ' and updated per-model overrides' : ''),
            meta: $data,
        );

        return response()->json($config->pricing_config);
    }
}
