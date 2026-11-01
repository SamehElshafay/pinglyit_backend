<?php

namespace App\Services\Meta;

use App\Models\MetaConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin client over Meta's Graph / Marketing API, always called with the
 * client's own token (see MetaConnection). Read side only for now —
 * accounts, Pages, campaigns and their insights.
 *
 * Meta's raw error bodies are logged, never returned: they can echo token
 * fragments and app ids. Clients get a plain-language message instead.
 */
class MetaGraphService
{
    /**
     * What Pingly asks the client to grant, and why — shown on the setup
     * screen, and checked against what the token actually has.
     */
    public const PERMISSIONS = [
        'ads_read' => 'See your campaigns and their results',
        'ads_management' => 'Create and edit campaigns when you ask the AI to',
        'business_management' => 'Find the ad accounts and Pages your business owns',
        'pages_show_list' => 'List your Facebook Pages',
        'pages_read_engagement' => 'Read your Page posts and their engagement',
        'pages_manage_posts' => 'Publish posts and videos to your Page when you ask',
        'instagram_basic' => 'Find the Instagram account linked to your Page',
        'instagram_content_publish' => 'Publish to Instagram when you ask',
    ];

    /** Phase 1 only reads; these are the ones it can't work without. */
    public const REQUIRED_NOW = ['ads_read', 'pages_show_list'];

    public function version(): string
    {
        return (string) config('pingly.meta.graph_version');
    }

    /**
     * Validate a pasted token and describe it: who it belongs to, what it
     * may do, when it expires, and which ad accounts / Pages it can see.
     *
     * @return array<string, mixed>
     */
    public function inspectToken(string $token): array
    {
        $me = $this->get($token, 'me', ['fields' => 'id,name']);

        $granted = collect($this->get($token, 'me/permissions')['data'] ?? [])
            ->where('status', 'granted')->pluck('permission')->values()->all();

        // debug_token tells us the expiry; a System User token answers
        // with expires_at = 0 ("never"). Best-effort — some token types
        // can't introspect themselves, which isn't a reason to refuse them.
        $expiresAt = null;
        try {
            $debug = $this->get($token, 'debug_token', ['input_token' => $token])['data'] ?? [];
            $expiresAt = ! empty($debug['expires_at']) ? (int) $debug['expires_at'] : null;
        } catch (RuntimeException) {
            // ignore
        }

        return [
            'user_id' => $me['id'] ?? null,
            'user_name' => $me['name'] ?? null,
            'granted' => $granted,
            'missing_required' => array_values(array_diff(self::REQUIRED_NOW, $granted)),
            'missing_optional' => array_values(array_diff(array_keys(self::PERMISSIONS), $granted, self::REQUIRED_NOW)),
            'expires_at' => $expiresAt,
            'ad_accounts' => $this->adAccounts($token),
            'pages' => $this->pages($token),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function adAccounts(string $token): array
    {
        $data = $this->get($token, 'me/adaccounts', ['fields' => 'id,name,account_status,currency,amount_spent', 'limit' => 100])['data'] ?? [];

        return collect($data)->map(fn ($a) => [
            'id' => $a['id'],
            'name' => $a['name'] ?? $a['id'],
            'currency' => $a['currency'] ?? null,
            // 1 = active; anything else (disabled, unsettled, in review…) is worth flagging
            'active' => (int) ($a['account_status'] ?? 0) === 1,
        ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function pages(string $token): array
    {
        $data = $this->get($token, 'me/accounts', ['fields' => 'id,name,instagram_business_account{id,username}', 'limit' => 100])['data'] ?? [];

        return collect($data)->map(fn ($p) => [
            'id' => $p['id'],
            'name' => $p['name'] ?? $p['id'],
            'instagram_id' => $p['instagram_business_account']['id'] ?? null,
            'instagram_username' => $p['instagram_business_account']['username'] ?? null,
        ])->values()->all();
    }

    /**
     * Campaigns with their numbers for the period, merged into one row each.
     *
     * @return array<int, array<string, mixed>>
     */
    public function campaigns(MetaConnection $conn, string $datePreset): array
    {
        $token = $conn->access_token;
        $act = $conn->ad_account_id;

        $campaigns = $this->get($token, "{$act}/campaigns", [
            'fields' => 'id,name,objective,status,effective_status,daily_budget,lifetime_budget,start_time,stop_time,created_time',
            'limit' => 200,
        ])['data'] ?? [];

        $insights = collect($this->get($token, "{$act}/insights", [
            'level' => 'campaign',
            'date_preset' => $datePreset,
            'fields' => 'campaign_id,spend,impressions,reach,frequency,clicks,ctr,cpc,cpm,actions,cost_per_action_type,purchase_roas',
            'limit' => 500,
        ])['data'] ?? [])->keyBy('campaign_id');

        // Budgets come back in the account currency's minor unit (cents/piasters).
        return collect($campaigns)->map(function ($c) use ($insights) {
            $i = $insights->get($c['id'], []);
            [$resultLabel, $results] = $this->primaryResult($c['objective'] ?? '', $i);
            $spend = (float) ($i['spend'] ?? 0);

            return [
                'id' => $c['id'],
                'name' => $c['name'],
                'objective' => $c['objective'] ?? null,
                'status' => $c['effective_status'] ?? $c['status'] ?? null,
                'daily_budget' => isset($c['daily_budget']) ? $c['daily_budget'] / 100 : null,
                'lifetime_budget' => isset($c['lifetime_budget']) ? $c['lifetime_budget'] / 100 : null,
                'start_time' => $c['start_time'] ?? null,
                'stop_time' => $c['stop_time'] ?? null,
                'spend' => $spend,
                'impressions' => (int) ($i['impressions'] ?? 0),
                'reach' => (int) ($i['reach'] ?? 0),
                'frequency' => round((float) ($i['frequency'] ?? 0), 2),
                'clicks' => (int) ($i['clicks'] ?? 0),
                'ctr' => round((float) ($i['ctr'] ?? 0), 2),
                'cpc' => round((float) ($i['cpc'] ?? 0), 2),
                'cpm' => round((float) ($i['cpm'] ?? 0), 2),
                'result_label' => $resultLabel,
                'results' => $results,
                'cost_per_result' => $results > 0 ? round($spend / $results, 2) : null,
                'roas' => isset($i['purchase_roas'][0]['value']) ? round((float) $i['purchase_roas'][0]['value'], 2) : null,
            ];
        })
            ->sortByDesc('spend')
            ->values()
            ->all();
    }

    /**
     * Account totals per day for the chart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dailyTotals(MetaConnection $conn, string $datePreset): array
    {
        $rows = $this->get($conn->access_token, "{$conn->ad_account_id}/insights", [
            'date_preset' => $datePreset,
            'time_increment' => 1,
            'fields' => 'spend,impressions,clicks',
            'limit' => 100,
        ])['data'] ?? [];

        return collect($rows)->map(fn ($r) => [
            'date' => $r['date_start'],
            'spend' => (float) ($r['spend'] ?? 0),
            'impressions' => (int) ($r['impressions'] ?? 0),
            'clicks' => (int) ($r['clicks'] ?? 0),
        ])->values()->all();
    }

    /**
     * Meta reports dozens of action types; which one is "the result"
     * depends on what the campaign was optimising for.
     *
     * @param  array<string, mixed>  $insight
     * @return array{0: string, 1: int}
     */
    private function primaryResult(string $objective, array $insight): array
    {
        $actions = collect($insight['actions'] ?? [])->pluck('value', 'action_type');

        $candidates = match ($objective) {
            'OUTCOME_SALES', 'CONVERSIONS', 'PRODUCT_CATALOG_SALES' => ['Purchases' => ['offsite_conversion.fb_pixel_purchase', 'purchase', 'omni_purchase', 'onsite_web_purchase']],
            'OUTCOME_LEADS', 'LEAD_GENERATION' => ['Leads' => ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead']],
            'MESSAGES' => ['Conversations' => ['onsite_conversion.messaging_conversation_started_7d']],
            'OUTCOME_ENGAGEMENT', 'POST_ENGAGEMENT', 'PAGE_LIKES' => [
                'Conversations' => ['onsite_conversion.messaging_conversation_started_7d'],
                'Engagements' => ['post_engagement', 'page_engagement'],
            ],
            'OUTCOME_TRAFFIC', 'LINK_CLICKS' => ['Link clicks' => ['link_click', 'landing_page_view']],
            'OUTCOME_APP_PROMOTION', 'APP_INSTALLS' => ['Installs' => ['mobile_app_install', 'app_install']],
            'VIDEO_VIEWS' => ['Video views' => ['video_view']],
            default => [],
        };

        foreach ($candidates as $label => $types) {
            foreach ($types as $type) {
                if ($actions->has($type) && (int) $actions[$type] > 0) {
                    return [$label, (int) $actions[$type]];
                }
            }
        }

        if (in_array($objective, ['OUTCOME_AWARENESS', 'BRAND_AWARENESS', 'REACH'], true)) {
            return ['Reach', (int) ($insight['reach'] ?? 0)];
        }

        return [array_key_first($candidates) ?? 'Results', 0];
    }

    /** @return array<string, mixed> */
    private function get(string $token, string $path, array $query = []): array
    {
        try {
            $response = $this->http($token)->get("https://graph.facebook.com/{$this->version()}/{$path}", $query);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Couldn't reach Meta right now — try again in a moment.");
        }

        return $this->unwrap($response, $path);
    }

    /** @return array<string, mixed> */
    private function unwrap(Response $response, string $path): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $error = $response->json('error') ?? [];
        Log::warning('Meta Graph request failed', ['path' => preg_replace('/\d{6,}/', '…', $path), 'status' => $response->status(), 'code' => $error['code'] ?? null, 'type' => $error['type'] ?? null]);

        throw new RuntimeException(match (true) {
            ($error['code'] ?? null) === 190 => 'Your Meta token has expired or was revoked — generate a new one and reconnect.',
            in_array($error['code'] ?? null, [10, 200, 294], true) || ($error['code'] ?? 0) >= 200 && ($error['code'] ?? 0) < 300 => 'Your Meta token is missing a permission this needs — check the setup steps.',
            in_array($error['code'] ?? null, [4, 17, 32, 613, 80004], true) => 'Meta is rate-limiting this account — wait a few minutes and try again.',
            ($error['code'] ?? null) === 100 => 'Meta rejected the request — make sure the ad account and Page are assigned to your System User.',
            default => 'Meta returned an error — try again, or reconnect your account.',
        });
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)->timeout(30)->acceptJson();
    }
}
