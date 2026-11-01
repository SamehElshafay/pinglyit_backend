<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\MetaConnection;
use App\Services\Meta\MetaAdsAdvisor;
use App\Services\Meta\MetaGraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Meta Ads, dashboard side: connect (paste a System User token, pick the
 * ad account + Page), then campaigns, their numbers, and an AI review.
 *
 * The token is stored encrypted and never sent back to the browser.
 */
class MetaController extends Controller
{
    private const PERIODS = ['today', 'yesterday', 'last_7d', 'last_14d', 'last_30d', 'last_90d', 'this_month', 'last_month', 'maximum'];

    public function __construct(
        private readonly MetaGraphService $meta,
        private readonly MetaAdsAdvisor $advisor,
    ) {}

    public function show(Request $request)
    {
        $conn = $request->user()->company->metaConnection;

        return response()->json([
            'connected' => (bool) $conn,
            'connection' => $conn ? $this->present($conn) : null,
            'permissions' => collect(MetaGraphService::PERMISSIONS)->map(fn ($why, $name) => [
                'name' => $name, 'why' => $why, 'required' => in_array($name, MetaGraphService::REQUIRED_NOW, true),
            ])->values(),
        ]);
    }

    /**
     * Step 1: check a pasted token and show what it can reach. Nothing is
     * saved until the client picks an ad account (connect()).
     */
    public function inspect(Request $request)
    {
        $data = $request->validate(['access_token' => ['required', 'string', 'min:40', 'max:1000']]);

        try {
            $info = $this->meta->inspectToken(trim($data['access_token']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($info);
    }

    /** Step 2: save the token with the chosen ad account and Page. */
    public function connect(Request $request)
    {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'min:40', 'max:1000'],
            'ad_account_id' => ['required', 'string', 'regex:/^act_\d+$/'],
            'page_id' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);
        $token = trim($data['access_token']);

        try {
            $info = $this->meta->inspectToken($token);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($info['missing_required']) {
            return response()->json(['message' => 'This token is missing: '.implode(', ', $info['missing_required']).'. Regenerate it with those permissions ticked.'], 422);
        }

        // Only accept an account/Page the token can actually see — the ids
        // come from the browser.
        $account = collect($info['ad_accounts'])->firstWhere('id', $data['ad_account_id']);
        $pageId = $data['page_id'] ?? null;
        $page = $pageId ? collect($info['pages'])->firstWhere('id', $pageId) : null;
        if (! $account || ($pageId && ! $page)) {
            return response()->json(['message' => 'That ad account or Page isn\'t assigned to this token.'], 422);
        }

        $company = $request->user()->company;
        $conn = MetaConnection::updateOrCreate(['company_id' => $company->id], [
            'access_token' => $token,
            'meta_user_id' => $info['user_id'],
            'meta_user_name' => $info['user_name'],
            'granted_permissions' => $info['granted'],
            'token_expires_at' => $info['expires_at'] ? Carbon::createFromTimestamp($info['expires_at']) : null,
            'ad_account_id' => $account['id'],
            'ad_account_name' => $account['name'],
            'currency' => $account['currency'],
            'page_id' => $page['id'] ?? null,
            'page_name' => $page['name'] ?? null,
            'instagram_account_id' => $page['instagram_id'] ?? null,
            'instagram_username' => $page['instagram_username'] ?? null,
            'status' => 'connected',
            'last_error' => null,
            'last_checked_at' => now(),
        ]);
        Cache::forget($this->cacheKey($conn, 'last_30d'));

        return response()->json(['connected' => true, 'connection' => $this->present($conn)]);
    }

    public function destroy(Request $request)
    {
        $request->user()->company->metaConnection?->delete();

        return response()->json(['connected' => false]);
    }

    public function campaigns(Request $request)
    {
        $period = $request->validate(['period' => ['nullable', Rule::in(self::PERIODS)]])['period'] ?? 'last_30d';
        $conn = $this->connection($request);

        try {
            // Five minutes: Meta's own numbers lag by more than that, and
            // the Marketing API rate-limits per ad account.
            $data = Cache::remember($this->cacheKey($conn, $period), 300, fn () => [
                'campaigns' => $this->meta->campaigns($conn, $period),
                'daily' => $this->meta->dailyTotals($conn, $period),
            ]);
        } catch (RuntimeException $e) {
            $conn->update(['last_error' => $e->getMessage(), 'last_checked_at' => now(),
                'status' => str_contains($e->getMessage(), 'expired') ? 'invalid' : $conn->status]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $c = collect($data['campaigns']);
        $spend = $c->sum('spend');
        $impressions = $c->sum('impressions');
        $clicks = $c->sum('clicks');

        return response()->json([
            'period' => $period,
            'currency' => $conn->currency,
            'totals' => [
                'spend' => round($spend, 2),
                'impressions' => $impressions,
                'reach' => $c->sum('reach'),
                'clicks' => $clicks,
                'ctr' => $impressions ? round($clicks / $impressions * 100, 2) : 0,
                'cpc' => $clicks ? round($spend / $clicks, 2) : 0,
                'cpm' => $impressions ? round($spend / $impressions * 1000, 2) : 0,
                'active_campaigns' => $c->where('status', 'ACTIVE')->count(),
            ],
            'campaigns' => $data['campaigns'],
            'daily' => $data['daily'],
        ]);
    }

    public function analyse(Request $request)
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(self::PERIODS)],
            'language' => ['nullable', Rule::in(['ar', 'en'])],
            'question' => ['nullable', 'string', 'max:1000'],
        ]);
        $conn = $this->connection($request);
        $period = $data['period'] ?? 'last_30d';
        @set_time_limit(120);

        try {
            $campaigns = Cache::remember($this->cacheKey($conn, $period), 300, fn () => [
                'campaigns' => $this->meta->campaigns($conn, $period),
                'daily' => $this->meta->dailyTotals($conn, $period),
            ])['campaigns'];

            if (! collect($campaigns)->sum('spend')) {
                return response()->json(['message' => 'No spend in this period — nothing to analyse yet. Try a longer period.'], 422);
            }

            $text = $this->advisor->analyse($request->user()->company, $conn, $campaigns, $period, $data['language'] ?? 'ar', $data['question'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['analysis' => $text]);
    }

    private function connection(Request $request): MetaConnection
    {
        $conn = $request->user()->company->metaConnection;
        abort_unless($conn && $conn->ad_account_id, 409, 'Connect your Meta account first.');

        return $conn;
    }

    private function cacheKey(MetaConnection $conn, string $period): string
    {
        return "meta:{$conn->company_id}:{$conn->ad_account_id}:{$period}";
    }

    /** @return array<string, mixed> */
    private function present(MetaConnection $conn): array
    {
        return [
            'meta_user_name' => $conn->meta_user_name,
            'ad_account_id' => $conn->ad_account_id,
            'ad_account_name' => $conn->ad_account_name,
            'currency' => $conn->currency,
            'page_name' => $conn->page_name,
            'instagram_username' => $conn->instagram_username,
            'granted_permissions' => $conn->granted_permissions ?? [],
            'missing_permissions' => array_values(array_diff(array_keys(MetaGraphService::PERMISSIONS), $conn->granted_permissions ?? [])),
            'token_expires_at' => $conn->token_expires_at,
            'status' => $conn->status,
            'last_error' => $conn->last_error,
        ];
    }
}
