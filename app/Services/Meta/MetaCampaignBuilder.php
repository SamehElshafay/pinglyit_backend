<?php

namespace App\Services\Meta;

use App\Models\Company;
use App\Models\MetaCampaign;
use App\Services\Ai\AiGatewayService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * The AI builds a Meta ad campaign from a brief and one of the client's
 * Studio creatives, and creates it on the client's own ad account.
 *
 * It spends the client's money, so three guard rails are structural:
 *   1. draft() only writes a plan; nothing touches Meta until the client
 *      reviews (and can edit) every field and presses create.
 *   2. Everything is created PAUSED. Going live is a separate, explicit
 *      setStatus('ACTIVE') the client triggers after seeing it in place.
 *   3. If any step fails halfway, the half-built campaign is deleted on
 *      Meta rather than left lying around in the account.
 *
 * Meta processes an uploaded video before an ad can use it, so building is
 * a resumable sequence (campaign → ad set → creative upload → creative →
 * ad); build() picks up from the last finished step, and the scheduler
 * calls it again while a video is still processing.
 */
class MetaCampaignBuilder
{
    /** What a client can ask for, and how each maps onto Meta's objectives. */
    public const GOALS = [
        'messages' => ['objective' => 'OUTCOME_ENGAGEMENT', 'optimization' => 'CONVERSATIONS', 'destination' => 'WHATSAPP', 'ctas' => ['WHATSAPP_MESSAGE']],
        'traffic' => ['objective' => 'OUTCOME_TRAFFIC', 'optimization' => 'LINK_CLICKS', 'destination' => 'WEBSITE', 'ctas' => ['SHOP_NOW', 'LEARN_MORE', 'ORDER_NOW', 'SIGN_UP', 'BOOK_NOW']],
        'awareness' => ['objective' => 'OUTCOME_AWARENESS', 'optimization' => 'REACH', 'destination' => null, 'ctas' => ['LEARN_MORE']],
        'video_views' => ['objective' => 'OUTCOME_ENGAGEMENT', 'optimization' => 'THRUPLAY', 'destination' => null, 'ctas' => ['LEARN_MORE']],
    ];

    public const COUNTRIES = ['EG', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'JO', 'LB', 'MA', 'IQ', 'US', 'GB'];

    public function __construct(
        private readonly MetaGraphService $graph,
        private readonly MetaPublisher $publisher,
        private readonly AiGatewayService $ai,
    ) {}

    /**
     * @param  array{video_project_id?: ?int, ai_image_id?: ?int}  $creative
     */
    public function draft(Company $company, string $goal, string $brief, array $creative, string $language): MetaCampaign
    {
        $conn = $company->metaConnection;
        if (! $conn?->ad_account_id || ! $conn->page_id) {
            throw new RuntimeException('Connect Meta with an ad account and a Facebook Page first.');
        }
        if (! isset(self::GOALS[$goal])) {
            throw new RuntimeException('Unknown campaign goal.');
        }
        if ($goal === 'video_views' && empty($creative['video_project_id'])) {
            throw new RuntimeException('A video-views campaign needs a video.');
        }

        $notes = $language === 'en' ? 'English' : 'Egyptian Arabic';
        $ctas = implode('|', self::GOALS[$goal]['ctas']);
        $countries = implode(', ', self::COUNTRIES);

        $response = $this->ai->forward($company, (string) config('pingly.meta.advisor_model'), [
            ['role' => 'system', 'content' => <<<TXT
            You are a senior Meta media buyer for brands in Egypt and the Gulf. Plan ONE campaign from the client's brief.
            Return ONLY a JSON object:
            {"name": "short campaign name", "daily_budget": number in {$conn->currency} per day, "days": 3-30,
             "countries": ["EG", …] from [{$countries}], "age_min": 18-65, "age_max": 18-65, "gender": "all|male|female",
             "primary_text": "the ad's main text, 1-3 short lines, in the audience's language and dialect, hook first",
             "headline": "max 40 chars", "call_to_action": "{$ctas}", "link": "https://… or null",
             "rationale": "3-5 sentences in {$notes} for the client: who this targets and why, why this budget and length, what result to expect and how to judge it after 3 days"}
            Rules: a realistic budget for this market and goal — enough for Meta's learning phase, never more than the brief allows; if the brief names a budget, use it.
            No claims the brief doesn't support. Audience = the people who'd actually buy, not everyone.
            TXT],
            ['role' => 'user', 'content' => "Goal: {$goal}\nBrief: {$brief}"],
        ], ['max_tokens' => 1500]);

        $plan = $this->normalise($goal, $this->parseJson((string) ($response['choices'][0]['message']['content'] ?? '')));

        return MetaCampaign::create([
            'company_id' => $company->id,
            'goal' => $goal,
            'brief' => $brief,
            'plan' => $plan,
            'video_project_id' => $creative['video_project_id'] ?? null,
            'ai_image_id' => $creative['ai_image_id'] ?? null,
            'status' => 'draft',
        ]);
    }

    /** @param array<string, mixed> $edits */
    public function updatePlan(MetaCampaign $campaign, array $edits): MetaCampaign
    {
        if ($campaign->status !== 'draft') {
            throw new RuntimeException('This campaign is already on Meta — change it from Ads Manager.');
        }
        $campaign->update(['plan' => $this->normalise($campaign->goal, array_merge($campaign->plan, $edits))]);

        return $campaign;
    }

    /**
     * Create (or continue creating) the campaign on Meta, PAUSED.
     * Returns once done, or once it's waiting on Meta to process a video.
     */
    public function build(MetaCampaign $campaign): MetaCampaign
    {
        if (! in_array($campaign->status, ['draft', 'creating'], true)) {
            return $campaign;
        }

        $conn = $campaign->company->metaConnection;
        if (! $conn?->ad_account_id || ! $conn->page_id) {
            throw new RuntimeException('Connect Meta with an ad account and a Facebook Page first.');
        }
        if (array_diff(['ads_management'], $conn->granted_permissions ?? [])) {
            throw new RuntimeException('Your Meta token is missing ads_management — regenerate it with that permission ticked.');
        }

        $campaign->update(['status' => 'creating', 'error' => null]);
        $goal = self::GOALS[$campaign->goal];
        $plan = $campaign->plan;
        $act = $conn->ad_account_id;
        $token = $conn->access_token;

        try {
            if (! $campaign->campaign_id) {
                $c = $this->graph->call('POST', $token, "{$act}/campaigns", [
                    'name' => $plan['name'],
                    'objective' => $goal['objective'],
                    'status' => 'PAUSED',
                    'special_ad_categories' => '[]',
                    'is_adset_budget_sharing_enabled' => 'false',
                ]);
                $campaign->update(['campaign_id' => $c['id']]);
            }

            if (! $campaign->adset_id) {
                $start = now()->addMinutes(15);
                $targeting = array_filter([
                    'geo_locations' => ['countries' => $plan['countries']],
                    'age_min' => $plan['age_min'],
                    'age_max' => $plan['age_max'],
                    'genders' => match ($plan['gender']) {
                        'male' => [1], 'female' => [2], default => null
                    },
                    // Explicit targeting the client approved — not Meta's
                    // automatic expansion, which would override it.
                    'targeting_automation' => ['advantage_audience' => 0],
                ], fn ($v) => $v !== null);

                $s = $this->graph->call('POST', $token, "{$act}/adsets", array_filter([
                    'name' => $plan['name'].' — audience',
                    'campaign_id' => $campaign->campaign_id,
                    'daily_budget' => (string) (int) round($plan['daily_budget'] * 100), // minor units
                    'billing_event' => 'IMPRESSIONS',
                    'optimization_goal' => $goal['optimization'],
                    'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                    'destination_type' => $goal['destination'],
                    'promoted_object' => $campaign->goal === 'messages' ? json_encode(['page_id' => $conn->page_id]) : null,
                    'targeting' => json_encode($targeting),
                    'start_time' => $start->toIso8601String(),
                    'end_time' => $start->copy()->addDays($plan['days'])->toIso8601String(),
                    'status' => 'PAUSED',
                ]));
                $campaign->update(['adset_id' => $s['id']]);
            }

            // Upload the creative into the ad account (Meta only takes media
            // it holds itself in an ad).
            if (empty($plan['image_hash'])) {
                $plan['image_hash'] = $this->uploadImage($token, $act, $this->creativeImagePath($campaign));
                $campaign->update(['plan' => $plan]);
            }
            if ($campaign->video_project_id && empty($plan['video_id'])) {
                $url = URL::temporarySignedRoute('video.media', now()->addHours(12), ['project' => $campaign->video_project_id, 'kind' => 'film']);
                $plan['video_id'] = $this->graph->call('POST', $token, "{$act}/advideos", ['file_url' => $url, 'name' => $plan['name']])['id'];
                $campaign->update(['plan' => $plan]);
            }
            if ($campaign->video_project_id) {
                $state = $this->graph->call('GET', $token, $plan['video_id'], ['fields' => 'status'])['status']['video_status'] ?? null;
                if ($state === 'error') {
                    throw new RuntimeException('Meta couldn\'t process the video for the ad.');
                }
                if ($state !== 'ready') {
                    return $campaign->fresh(); // the scheduler continues once Meta has processed it
                }
            }

            if (empty($plan['creative_id'])) {
                $plan['creative_id'] = $this->graph->call('POST', $token, "{$act}/adcreatives", [
                    'name' => $plan['name'].' — creative',
                    'object_story_spec' => json_encode($this->storySpec($campaign, $conn->page_id, $conn->instagram_account_id, $plan)),
                ])['id'];
                $campaign->update(['plan' => $plan]);
            }

            if (! $campaign->ad_id) {
                $ad = $this->graph->call('POST', $token, "{$act}/ads", [
                    'name' => $plan['name'].' — ad',
                    'adset_id' => $campaign->adset_id,
                    'creative' => json_encode(['creative_id' => $plan['creative_id']]),
                    'status' => 'PAUSED',
                ]);
                $campaign->update(['ad_id' => $ad['id']]);
            }

            $campaign->update(['status' => 'created']);
        } catch (RuntimeException $e) {
            $this->rollBack($campaign, $token);
            $campaign->update(['status' => 'failed', 'error' => $e->getMessage()]);

            throw $e;
        }

        return $campaign->fresh();
    }

    /** Switch a created campaign on or off — ad, ad set and campaign together. */
    public function setStatus(MetaCampaign $campaign, string $status): MetaCampaign
    {
        if (! in_array($campaign->status, ['created', 'active', 'paused'], true)) {
            throw new RuntimeException('This campaign isn\'t on Meta yet.');
        }
        $token = $campaign->company->metaConnection?->access_token;
        if (! $token) {
            throw new RuntimeException('Reconnect Meta first.');
        }

        $ids = $status === 'ACTIVE'
            ? [$campaign->ad_id, $campaign->adset_id, $campaign->campaign_id]   // inside-out, so nothing goes live half-built
            : [$campaign->campaign_id];                                         // pausing the campaign stops everything under it
        foreach ($ids as $id) {
            $this->graph->call('POST', $token, $id, ['status' => $status]);
        }
        $campaign->update(['status' => $status === 'ACTIVE' ? 'active' : 'paused']);

        return $campaign;
    }

    /** Scheduler: finish campaigns waiting on Meta to process their video. */
    public function advance(): void
    {
        foreach (MetaCampaign::where('status', 'creating')->where('updated_at', '<', now()->subSeconds(50))->get() as $campaign) {
            try {
                $this->build($campaign);
            } catch (\Throwable $e) {
                Log::warning('Meta campaign build failed', ['campaign' => $campaign->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function storySpec(MetaCampaign $campaign, string $pageId, ?string $igId, array $plan): array
    {
        $cta = $plan['call_to_action'];
        $link = $campaign->goal === 'messages' ? 'https://api.whatsapp.com/send' : ($plan['link'] ?: "https://www.facebook.com/{$pageId}");
        $ctaValue = $campaign->goal === 'messages' ? ['app_destination' => 'WHATSAPP'] : ['link' => $link];

        $spec = ['page_id' => $pageId];
        if ($igId) {
            $spec['instagram_user_id'] = $igId;
        }

        if ($campaign->video_project_id) {
            $spec['video_data'] = [
                'video_id' => $plan['video_id'],
                'image_hash' => $plan['image_hash'],
                'message' => $plan['primary_text'],
                'title' => $plan['headline'],
                'call_to_action' => ['type' => $cta, 'value' => $ctaValue],
            ];
        } else {
            $spec['link_data'] = [
                'image_hash' => $plan['image_hash'],
                'link' => $link,
                'message' => $plan['primary_text'],
                'name' => $plan['headline'],
                'call_to_action' => ['type' => $cta, 'value' => $ctaValue],
            ];
        }

        return $spec;
    }

    private function creativeImagePath(MetaCampaign $campaign): string
    {
        $path = $campaign->video_project_id
            ? $campaign->videoProject?->thumbnail_path
            : $campaign->aiImage?->path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException($campaign->video_project_id
                ? 'This video has no poster image to use as the ad thumbnail — pick another video.'
                : 'The image for this ad is missing — pick another one.');
        }

        return $path;
    }

    private function uploadImage(string $token, string $act, string $path): string
    {
        $res = $this->graph->call('POST', $token, "{$act}/adimages", ['bytes' => base64_encode(Storage::disk('local')->get($path))]);
        $hash = collect($res['images'] ?? [])->first()['hash'] ?? null;
        if (! $hash) {
            throw new RuntimeException('Meta didn\'t accept the ad image.');
        }

        return $hash;
    }

    private function rollBack(MetaCampaign $campaign, string $token): void
    {
        if (! $campaign->campaign_id) {
            return;
        }
        try {
            $this->graph->call('POST', $token, $campaign->campaign_id, ['status' => 'DELETED']);
        } catch (\Throwable) {
            // best effort — it was created PAUSED, so it spends nothing either way
        }
        $campaign->update(['campaign_id' => null, 'adset_id' => null, 'ad_id' => null]);
    }

    /**
     * Never trust the model (or the browser) with these numbers.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function normalise(string $goal, array $p): array
    {
        $ctas = self::GOALS[$goal]['ctas'];
        $countries = array_values(array_intersect(array_map('strtoupper', (array) ($p['countries'] ?? [])), self::COUNTRIES));
        $ageMin = max(18, min(65, (int) ($p['age_min'] ?? 18)));
        $link = is_string($p['link'] ?? null) && preg_match('#^https://#', $p['link']) ? mb_substr($p['link'], 0, 500) : null;

        if ($goal === 'traffic' && ! $link && ! empty($p['_require_link'])) {
            throw new RuntimeException('A traffic campaign needs a link (https://…).');
        }

        return array_merge($p, [
            'name' => mb_substr(trim((string) ($p['name'] ?? 'Pingly campaign')), 0, 80) ?: 'Pingly campaign',
            'daily_budget' => max(1, round((float) ($p['daily_budget'] ?? 5), 2)),
            'days' => max(1, min(90, (int) ($p['days'] ?? 7))),
            'countries' => $countries ?: ['EG'],
            'age_min' => $ageMin,
            'age_max' => max($ageMin, min(65, (int) ($p['age_max'] ?? 65))),
            'gender' => in_array($p['gender'] ?? 'all', ['all', 'male', 'female'], true) ? $p['gender'] ?? 'all' : 'all',
            'primary_text' => mb_substr(trim((string) ($p['primary_text'] ?? '')), 0, 1000),
            'headline' => mb_substr(trim((string) ($p['headline'] ?? '')), 0, 60),
            'call_to_action' => in_array($p['call_to_action'] ?? null, $ctas, true) ? $p['call_to_action'] : $ctas[0],
            'link' => $link,
            'rationale' => mb_substr((string) ($p['rationale'] ?? ''), 0, 2000),
        ]);
    }

    /** @return array<string, mixed> */
    private function parseJson(string $content): array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($content, $start, $end - $start + 1), true) : null;
        if (! is_array($data)) {
            throw new RuntimeException('Could not plan this campaign — try describing it differently.');
        }

        return $data;
    }
}
