<?php

namespace App\Services\Ai;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
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
     * Forward a request to OpenRouter. Balance is checked with a rough
     * estimate up front; the real bill is only known once OpenRouter
     * responds with actual usage, which is why recordCompletion() below is
     * the step that actually touches the wallet.
     *
     * @param  array<int, array<string, string>>  $messages
     */
    public function forward(Company $company, string $model, array $messages): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('AI Gateway is not configured — set OPENROUTER_API_KEY in .env.');
        }

        // TODO: call OpenRouter's chat completions endpoint via
        // Http::withToken(config('pingly.ai.openrouter_api_key'))
        //   ->post(config('pingly.ai.openrouter_api_base').'/chat/completions', [...]);
        // The response carries real token usage + real cost, which
        // recordCompletion() below turns into a UsageEvent + wallet debit.
        throw new RuntimeException('OpenRouter integration not implemented yet — see TODO in '.self::class);
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
