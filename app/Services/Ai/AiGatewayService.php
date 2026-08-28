<?php

namespace App\Services\Ai;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service B (docs §4). Same registration pattern as WhatsAppGatewayService —
 * both just call BillingEngine::recordUsage() with their own service_type.
 *
 * Pricing config shape (platform default):
 *   [
 *     'multiplier' => 5.0,               // fallback for any model with no entry below
 *     'model_overrides' => ['openai/gpt-4o' => 3.0, 'openai/gpt-4o-mini' => 8.0, ...],
 *                                        // the primary mechanism (decided, not optional
 *                                        // anymore) — pricier models get a lower
 *                                        // multiplier, cheap ones a higher one, since a
 *                                        // flat rate under- or over-charges at the extremes.
 *   ]
 *
 * The wallet is real USD (Stripe top-ups charge real dollars 1:1 into it —
 * see StripeGateway), so billing is real_cost_usd × multiplier, full stop.
 * `billed_tokens` (real_tokens × multiplier) exists only as a display number
 * in usage history — it is never itself multiplied into a dollar amount.
 * (A v1 of this file did exactly that via a `token_to_currency_rate` at
 * 1.0 — i.e. billed 1 "token" as $1 — which is how two three-cent test
 * messages emptied $300 out of a wallet. Removed; don't reintroduce a
 * token→dollar rate without checking it against real per-token pricing
 * first, which sits around $0.0000001–0.00003/token on OpenRouter.)
 *
 * A per-client override (docs §2.1, e.g. "Client X = 4x") stays a single
 * flat multiplier that ignores model — it's a blanket override, not a
 * second per-model matrix on top of the first.
 */
class AiGatewayService
{
    public function __construct(
        private readonly BillingEngine $billing,
        private readonly ServiceConfigRepository $configs,
    ) {}

    /**
     * The admin enters this from the dashboard (AI Gateway → connection) —
     * it lives encrypted in platform_settings, not .env. OPENROUTER_API_KEY
     * still works as a fallback if someone prefers env-based config, but
     * the DB value always wins when both are set.
     */
    public function apiKey(): ?string
    {
        return PlatformSetting::get('openrouter_api_key') ?: config('pingly.ai.openrouter_api_key');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    public function isEnabledFor(Company $company): bool
    {
        return $this->configs->clientOverride($company, ServiceType::Ai)?->pricing_config['enabled'] ?? true;
    }

    public function setEnabledFor(Company $company, bool $enabled): void
    {
        $this->configs->setClientFlag($company, ServiceType::Ai, 'enabled', $enabled);
    }

    /**
     * @return array<string, mixed>
     */
    public function pricingFor(Company $company): array
    {
        $pricing = $this->configs->resolve($company, ServiceType::Ai);

        return array_merge([
            'multiplier' => config('pingly.ai.default_multiplier'),
            'model_overrides' => [],
        ], $pricing);
    }

    public function multiplierFor(Company $company, ?string $model = null): float
    {
        $pricing = $this->pricingFor($company);

        if ($model && isset($pricing['model_overrides'][$model])) {
            return (float) $pricing['model_overrides'][$model];
        }

        return (float) $pricing['multiplier'];
    }

    /**
     * A starting menu for the admin's "add a per-model override" picker —
     * real OpenRouter model ids, picked for being either cheap or well-known
     * (checked against OpenRouter's live pricing on 2026-08-28). Not
     * exhaustive — OpenRouter lists ~400 models; admin can still type a
     * different id by hand. Adding a model here doesn't make it billable on
     * its own — it still needs a multiplier set on the pricing screen.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function suggestedModels(): array
    {
        return [
            ['id' => 'mistralai/mistral-nemo', 'name' => 'Mistral Nemo (cheapest)'],
            ['id' => 'meta-llama/llama-3.1-8b-instruct', 'name' => 'Llama 3.1 8B Instruct'],
            ['id' => 'mistralai/mistral-small-24b-instruct-2501', 'name' => 'Mistral Small 24B'],
            ['id' => 'openai/gpt-oss-20b', 'name' => 'GPT-OSS 20B'],
            ['id' => 'google/gemma-3-4b-it', 'name' => 'Gemma 3 4B'],
            ['id' => 'google/gemini-2.5-flash-lite', 'name' => 'Gemini 2.5 Flash Lite'],
            ['id' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash'],
            ['id' => 'google/gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro'],
            ['id' => 'openai/gpt-4o-mini', 'name' => 'GPT-4o Mini'],
            ['id' => 'openai/gpt-4o', 'name' => 'GPT-4o'],
            ['id' => 'anthropic/claude-3.5-sonnet', 'name' => 'Claude 3.5 Sonnet'],
        ];
    }

    /**
     * What a client is actually allowed to pick from — the models the admin
     * has priced for this client (their own override if they have one, else
     * the platform default's list). Not the full OpenRouter catalog: a
     * client only sees models Pingly has actually set a rate for.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function availableModelsFor(Company $company): array
    {
        $overrides = $this->pricingFor($company)['model_overrides'] ?? [];
        $names = collect($this->suggestedModels())->keyBy('id');

        return collect(array_keys($overrides))
            ->map(fn (string $id) => ['id' => $id, 'name' => $names->get($id)['name'] ?? $id])
            ->values()
            ->all();
    }

    /**
     * Forward a request to OpenRouter, bill it, return the completion.
     * Real cost can only be known after OpenRouter responds with actual
     * token usage — so the only pre-flight check possible is "is the
     * wallet not already empty", not an exact estimate (docs §4.4's flow:
     * send → real usage/cost comes back → then bill).
     *
     * @param  array<int, array<string, string>>  $messages
     */
    public function forward(Company $company, string $model, array $messages): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('AI Gateway is not configured — add the OpenRouter key in the admin dashboard first.');
        }

        if (! $this->billing->hasSufficientBalance($company, 0.000001)) {
            throw new RuntimeException('Wallet balance is empty — top up before making AI requests.');
        }

        try {
            $response = Http::withToken($this->apiKey())
                ->timeout(60)
                ->post(config('pingly.ai.openrouter_api_base').'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Couldn't reach OpenRouter: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new RuntimeException('OpenRouter request failed: '.$response->body());
        }

        $data = $response->json();
        $usage = $data['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $realTokens = (int) ($usage['total_tokens'] ?? 0);
        $realCost = $this->estimateRealCost($model, $usage);

        $this->recordCompletion($company, $model, $realTokens, $realCost);

        return $data;
    }

    /**
     * OpenRouter doesn't return cost inline with the completion — its
     * per-token prompt/completion pricing comes from GET /models, cached
     * for an hour so a chat request doesn't cost two HTTP round trips.
     *
     * @param  array{prompt_tokens?: int, completion_tokens?: int}  $usage
     */
    private function estimateRealCost(string $model, array $usage): float
    {
        // The completion above already happened — a failure here shouldn't
        // turn into a 500 after the client already got their answer. Worst
        // case this one request is costed at $0 and shows up for review in
        // the admin's AI logs (real_cost = 0 is easy to spot there).
        $pricing = Cache::remember("openrouter_pricing:{$model}", 3600, function () use ($model) {
            try {
                $response = Http::withToken($this->apiKey())
                    ->get(config('pingly.ai.openrouter_api_base').'/models');
            } catch (ConnectionException $e) {
                Log::warning('OpenRouter pricing lookup failed', ['model' => $model, 'error' => $e->getMessage()]);

                return ['prompt' => 0, 'completion' => 0];
            }

            $found = collect($response->json('data', []))->firstWhere('id', $model);

            return $found['pricing'] ?? ['prompt' => 0, 'completion' => 0];
        });

        return round(
            (int) ($usage['prompt_tokens'] ?? 0) * (float) ($pricing['prompt'] ?? 0)
            + (int) ($usage['completion_tokens'] ?? 0) * (float) ($pricing['completion'] ?? 0),
            8,
        );
    }

    public function recordCompletion(Company $company, string $model, int $realTokens, float $realCostUsd): void
    {
        $multiplier = $this->multiplierFor($company, $model);
        $billedTokens = $realTokens * $multiplier; // display-only — never itself converted to dollars
        $billedAmount = round($realCostUsd * $multiplier, 6); // the actual wallet debit: real $ × margin

        $this->billing->recordUsage(
            company: $company,
            service: ServiceType::Ai,
            rawCostToPingly: $realCostUsd,
            billedAmountToClient: $billedAmount,
            multiplierOrMargin: $multiplier,
            metadata: ['model' => $model, 'real_tokens' => $realTokens, 'billed_tokens' => $billedTokens],
        );
    }
}
