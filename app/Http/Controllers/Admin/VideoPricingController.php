<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\UsageEvent;
use App\Models\VideoProject;
use App\Services\Billing\ServiceConfigRepository;
use App\Services\Video\VideoModelCatalog;
use App\Services\Video\VideoStitcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Video Studio margin + which models clients may use. Same shape as the AI
 * Gateway's pricing config (a fallback multiplier, per-model overrides),
 * plus a list of models switched off platform-wide.
 */
class VideoPricingController extends Controller
{
    public function __construct(
        private readonly ServiceConfigRepository $configs,
        private readonly VideoModelCatalog $catalog,
        private readonly VideoStitcher $stitcher,
    ) {}

    public function show()
    {
        $config = array_merge([
            'multiplier' => config('pingly.video.default_multiplier'),
            'model_overrides' => [],
            'disabled_models' => [],
        ], $this->configs->platformDefault(ServiceType::Video));

        return response()->json([
            'config' => $config,
            // Raw cost per second at 720p 16:9 with audio — the admin's
            // number for setting a margin, never shown to clients.
            'models' => collect($this->catalog->all())->map(fn ($m, $id) => [
                'id' => $id,
                'name' => $m['name'],
                'tagline' => $m['tagline'],
                'raw_per_second' => collect($m['resolutions'])->mapWithKeys(fn ($res) => [
                    $res => $this->catalog->estimateShotCost($id, $res, '16:9', 1, true, 0),
                ]),
            ])->values(),
            'ffmpeg_available' => $this->stitcher->available(),
            'stats' => [
                'projects_in_progress' => VideoProject::whereIn('status', VideoProject::ACTIVE_STATUSES)->count(),
                'films_completed' => VideoProject::where('status', 'completed')->count(),
                'raw_cost_total' => (float) UsageEvent::where('service_type', ServiceType::Video)->sum('raw_cost_to_pingly'),
                'billed_total' => (float) UsageEvent::where('service_type', ServiceType::Video)->sum('billed_amount_to_client'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $ids = array_keys($this->catalog->all());
        $data = $request->validate([
            'multiplier' => ['required', 'numeric', 'min:1'],
            'model_overrides' => ['sometimes', 'array'],
            'model_overrides.*' => ['numeric', 'min:1'],
            'disabled_models' => ['sometimes', 'array'],
            'disabled_models.*' => ['string', Rule::in($ids)],
        ]);

        $existing = $this->configs->platformDefault(ServiceType::Video);
        $config = $this->configs->setPlatformDefault(ServiceType::Video, array_merge($existing, $data));

        AuditLog::record(
            $request->user(),
            'video_pricing.update',
            "Set Video Studio fallback multiplier to {$data['multiplier']}x",
            meta: $data,
        );

        return response()->json($config->pricing_config);
    }
}
