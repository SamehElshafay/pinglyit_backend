<?php

namespace App\Services\Ai;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
 *     'token_to_currency_rate' => 1.0,   // docs §4.7 open decision: how a
 *                                        // billed token maps to wallet currency.
 *                                        // 1.0 = a token *is* the credit unit.
 *   ]
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

    public function isConfigured(): bool
    {
        return filled(config('pingly.ai.openrouter_api_key'));
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
            'token_to_currency_rate' => 1.0,
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
            throw new RuntimeException('AI Gateway is not configured — set OPENROUTER_API_KEY in .env.');
        }

        if (! $this->billing->hasSufficientBalance($company, 0.000001)) {
            throw new RuntimeException('Wallet balance is empty — top up before making AI requests.');
        }

        $response = Http::withToken(config('pingly.ai.openrouter_api_key'))
            ->timeout(60)
            ->post(config('pingly.ai.openrouter_api_base').'/chat/completions', [
                'model' => $model,
                'messages' => $messages,
            ]);

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
        $pricing = Cache::remember("openrouter_pricing:{$model}", 3600, function () use ($model) {
            $response = Http::withToken(config('pingly.ai.openrouter_api_key'))
                ->get(config('pingly.ai.openrouter_api_base').'/models');

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
        $pricing = $this->pricingFor($company);
        $multiplier = $this->multiplierFor($company, $model);
        $billedTokens = $realTokens * $multiplier;
        $billedAmount = round($billedTokens * (float) $pricing['token_to_currency_rate'], 6);

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
