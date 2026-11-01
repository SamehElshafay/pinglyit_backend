<?php

namespace App\Services\Meta;

use App\Models\Company;
use App\Models\MetaConnection;
use App\Services\Ai\AiGatewayService;

/**
 * A senior media buyer reading the client's real numbers. Billed to the
 * client as an ordinary AI Gateway request.
 *
 * Only the numbers go in — campaign names, objectives, budgets, results —
 * never the token or account ids beyond what's needed to tell rows apart.
 */
class MetaAdsAdvisor
{
    public function __construct(private readonly AiGatewayService $ai) {}

    /**
     * @param  array<int, array<string, mixed>>  $campaigns  from MetaGraphService::campaigns()
     */
    public function analyse(Company $company, MetaConnection $conn, array $campaigns, string $period, string $language, ?string $question): string
    {
        $rows = collect($campaigns)
            ->filter(fn ($c) => $c['spend'] > 0 || in_array($c['status'], ['ACTIVE', 'IN_PROCESS', 'WITH_ISSUES'], true))
            ->take(40)
            ->map(fn ($c) => collect($c)->only([
                'name', 'objective', 'status', 'daily_budget', 'lifetime_budget', 'spend', 'impressions', 'reach',
                'frequency', 'clicks', 'ctr', 'cpc', 'cpm', 'result_label', 'results', 'cost_per_result', 'roas',
            ])->all())
            ->values();

        $languageLine = $language === 'ar'
            ? 'Write the whole answer in Egyptian Arabic (عامية مصرية مهنية), keeping metric names like CTR, CPC, ROAS in English.'
            : 'Write the answer in clear, plain English.';

        $user = "Ad account currency: {$conn->currency}\nPeriod: {$period}\n"
            ."Campaigns (JSON):\n".json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            .($question ? "\n\nThe client's specific question: {$question}" : '');

        $response = $this->ai->forward($company, (string) config('pingly.meta.advisor_model'), [
            ['role' => 'system', 'content' => $this->systemPrompt()."\n\n".$languageLine],
            ['role' => 'user', 'content' => $user],
        ], ['max_tokens' => 3000]);

        return trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You are a senior performance marketer who has managed Meta (Facebook & Instagram) ad budgets for brands in Egypt and the Gulf for ten years. A business owner is showing you their real campaign numbers. Be direct, specific and practical — they want decisions, not a lecture.

        Structure your answer with these short sections (use the headings, keep each tight):
        1. The verdict — two or three sentences: is this account healthy, wasting money, or leaving money on the table?
        2. What's working — name the campaigns, quote the numbers that prove it.
        3. What's wasting money — name the campaigns, quote the numbers, say why (creative fatigue: frequency above ~3 with falling CTR; weak hook: CTR under ~0.8%; expensive audience: CPM well above the rest; wrong objective for the goal; budget spread too thin across too many campaigns).
        4. Do this now — at most 5 concrete actions, highest impact first, each with the expected effect. E.g. "Pause X (spent 4,200 for 3 purchases, cost per purchase 1,400 vs 380 on Y)", "Move its budget to Y", "Refresh the creative on Z".
        5. Next test — one experiment worth running next, and how to judge it.

        Rules:
        - Use only the numbers given. If something can't be judged from them (no pixel data, too little spend to be significant), say so instead of guessing.
        - Judge results against the campaign's own objective — never criticise an awareness campaign for having no purchases.
        - Money in the account's currency, rounded sensibly.
        - Benchmarks are rough guides for the region, not rules; say "for this market" rather than claiming universal truths.
        - If the client asked a question, answer it first, then the rest.
        TXT;
    }
}
