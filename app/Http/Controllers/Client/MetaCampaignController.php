<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\AiImage;
use App\Models\MetaCampaign;
use App\Services\Meta\MetaCampaignBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Campaigns the AI builds on the client's ad account — see
 * MetaCampaignBuilder for the draft → create (paused) → switch on flow.
 */
class MetaCampaignController extends Controller
{
    public function __construct(private readonly MetaCampaignBuilder $builder) {}

    public function index(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            'goals' => array_keys(MetaCampaignBuilder::GOALS),
            'countries' => MetaCampaignBuilder::COUNTRIES,
            'ctas' => collect(MetaCampaignBuilder::GOALS)->map(fn ($g) => $g['ctas']),
            'currency' => $company->metaConnection?->currency,
            // What the client can advertise with: finished films and generated images.
            'creatives' => [
                'videos' => $company->videoProjects()->where('status', 'completed')->latest('id')->limit(30)->get()
                    ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'aspect_ratio' => $p->aspect_ratio,
                        'poster_url' => $p->thumbnail_path ? URL::temporarySignedRoute('video.media', now()->addHours(6), ['project' => $p->id, 'kind' => 'poster']) : null]),
                'images' => AiImage::where('company_id', $company->id)->whereNotNull('path')->latest('id')->limit(30)->get()
                    ->map(fn ($i) => ['id' => $i->id, 'prompt' => $i->prompt, 'url' => URL::temporarySignedRoute('image.media', now()->addHours(6), ['image' => $i->id])]),
            ],
            'campaigns' => MetaCampaign::where('company_id', $company->id)->latest('id')->limit(50)->get()->map(fn ($c) => $this->present($c)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'goal' => ['required', Rule::in(array_keys(MetaCampaignBuilder::GOALS))],
            'brief' => ['required', 'string', 'min:10', 'max:3000'],
            'video_project_id' => ['nullable', 'integer', 'required_without:ai_image_id'],
            'ai_image_id' => ['nullable', 'integer', 'required_without:video_project_id'],
            'language' => ['nullable', 'in:ar,en'],
        ]);
        $company = $request->user()->company;

        // The creative must be this company's own, and finished.
        if (! empty($data['video_project_id']) && ! $company->videoProjects()->whereKey($data['video_project_id'])->where('status', 'completed')->exists()) {
            return response()->json(['message' => 'Pick one of your finished videos.'], 422);
        }
        if (! empty($data['ai_image_id']) && ! AiImage::where('company_id', $company->id)->whereKey($data['ai_image_id'])->exists()) {
            return response()->json(['message' => 'Pick one of your images.'], 422);
        }
        @set_time_limit(120);

        try {
            $campaign = $this->builder->draft($company, $data['goal'], $data['brief'], [
                'video_project_id' => $data['video_project_id'] ?? null,
                'ai_image_id' => empty($data['video_project_id']) ? ($data['ai_image_id'] ?? null) : null,
            ], $data['language'] ?? 'ar');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($campaign), 201);
    }

    public function update(Request $request, int $campaign)
    {
        $campaign = $this->find($request, $campaign);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'daily_budget' => ['sometimes', 'numeric', 'min:1', 'max:100000'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'countries' => ['sometimes', 'array', 'min:1'],
            'countries.*' => [Rule::in(MetaCampaignBuilder::COUNTRIES)],
            'age_min' => ['sometimes', 'integer', 'min:18', 'max:65'],
            'age_max' => ['sometimes', 'integer', 'min:18', 'max:65'],
            'gender' => ['sometimes', 'in:all,male,female'],
            'primary_text' => ['sometimes', 'string', 'max:1000'],
            'headline' => ['sometimes', 'string', 'max:60'],
            'call_to_action' => ['sometimes', 'string'],
            'link' => ['sometimes', 'nullable', 'url:https', 'max:500'],
        ]);

        try {
            $campaign = $this->builder->updatePlan($campaign, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($campaign));
    }

    /** Create it on Meta — paused. */
    public function launch(Request $request, int $campaign)
    {
        $campaign = $this->find($request, $campaign);
        if ($campaign->goal === 'traffic' && empty($campaign->plan['link'])) {
            return response()->json(['message' => 'A traffic campaign needs a link (https://…) — add it first.'], 422);
        }
        @set_time_limit(180);

        try {
            $campaign = $this->builder->build($campaign);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'campaign' => $this->present($campaign->fresh())], 422);
        }

        return response()->json($this->present($campaign));
    }

    public function status(Request $request, int $campaign)
    {
        $campaign = $this->find($request, $campaign);
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,PAUSED']]);

        try {
            $campaign = $this->builder->setStatus($campaign, $data['status']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($campaign));
    }

    public function destroy(Request $request, int $campaign)
    {
        $campaign = $this->find($request, $campaign);
        if (! in_array($campaign->status, ['draft', 'failed'], true)) {
            return response()->json(['message' => 'This campaign is on Meta — pause or delete it from Ads Manager.'], 422);
        }
        $campaign->delete();

        return response()->noContent();
    }

    private function find(Request $request, int $id): MetaCampaign
    {
        return MetaCampaign::where('company_id', $request->user()->company_id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(MetaCampaign $c): array
    {
        $plan = collect($c->plan)->except(['image_hash', 'video_id', 'creative_id'])->all();

        return $c->only(['id', 'goal', 'brief', 'status', 'error', 'video_project_id', 'ai_image_id', 'campaign_id', 'created_at']) + [
            'plan' => $plan,
            'total_budget' => round(($plan['daily_budget'] ?? 0) * ($plan['days'] ?? 0), 2),
            'ads_manager_url' => $c->campaign_id
                ? 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act='.str_replace('act_', '', (string) $c->company->metaConnection?->ad_account_id).'&selected_campaign_ids='.$c->campaign_id
                : null,
        ];
    }
}
