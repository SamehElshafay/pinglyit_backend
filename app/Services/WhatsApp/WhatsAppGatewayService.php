<?php

namespace App\Services\WhatsApp;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use RuntimeException;

/**
 * Service A (docs §3). Registers into the shared billing engine by service
 * type only — Wallet/UsageEvent/ServiceConfig never mention "whatsapp"
 * anywhere in their own code.
 *
 * Pricing config shape (platform default, editable via the admin dashboard —
 * never hardcoded, per docs §3.5):
 *   [
 *     'margin_percent' => 25.0,
 *     'monthly_fee' => 15.0,
 *     'base_costs' => [
 *       ['category' => 'utility', 'country' => 'EG', 'cost' => 0.0300],
 *       ...
 *     ],
 *   ]
 * A client override may replace any of these keys (e.g. just 'margin_percent').
 */
class WhatsAppGatewayService
{
    public function __construct(
        private readonly BillingEngine $billing,
        private readonly ServiceConfigRepository $configs,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('pingly.whatsapp.access_token'));
    }

    public function isEnabledFor(Company $company): bool
    {
        return $this->configs->clientOverride($company, ServiceType::WhatsApp)?->pricing_config['enabled'] ?? true;
    }

    public function setEnabledFor(Company $company, bool $enabled): void
    {
        $this->configs->setClientFlag($company, ServiceType::WhatsApp, 'enabled', $enabled);
    }

    /**
     * @return array<string, mixed>
     */
    public function pricingFor(Company $company): array
    {
        return $this->configs->resolve($company, ServiceType::WhatsApp);
    }

    /**
     * cost-to-Pingly + margin, for one message category/country — the same
     * math the admin's WhatsApp pricing screen and this service both use.
     */
    public function estimateCost(Company $company, string $category, string $country): float
    {
        $pricing = $this->pricingFor($company);
        $base = collect($pricing['base_costs'] ?? [])
            ->first(fn ($row) => $row['category'] === $category && $row['country'] === $country);

        if (! $base) {
            throw new RuntimeException("No base cost configured for {$category}/{$country} — add it to the WhatsApp pricing config first.");
        }

        $margin = (float) ($pricing['margin_percent'] ?? 0);

        return round((float) $base['cost'] * (1 + $margin / 100), 6);
    }

    /**
     * Send one message. Pre-flight balance check, then the real Meta call,
     * then records the real cost against the wallet.
     *
     * @param  array<string, mixed>  $payload  the WhatsApp message payload (template, text, etc.)
     */
    public function send(Company $company, string $to, string $category, string $country, array $payload): array
    {
        $estimatedCost = $this->estimateCost($company, $category, $country);

        if (! $this->billing->hasSufficientBalance($company, $estimatedCost)) {
            throw new RuntimeException('Wallet balance is too low to send this message.');
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('WhatsApp Gateway is not configured — set META_WHATSAPP_* in .env.');
        }

        // TODO: call Meta's WhatsApp Cloud API (POST /{phone-number-id}/messages)
        // via Http::withToken(config('pingly.whatsapp.access_token')), using
        // $company->whatsappAccounts for the sending number. Real per-message
        // cost/status comes back from Meta's response + webhook delivery receipts.
        throw new RuntimeException('Meta Cloud API integration not implemented yet — see TODO in '.self::class);
    }

    /**
     * Called once the real cost is known (from Meta's response or webhook) —
     * this is the actual billing step; send() above is the request path.
     */
    public function recordDeliveredMessage(Company $company, string $category, string $country, float $realCost): void
    {
        $pricing = $this->pricingFor($company);
        $margin = (float) ($pricing['margin_percent'] ?? 0);
        $billed = round($realCost * (1 + $margin / 100), 6);

        $this->billing->recordUsage(
            company: $company,
            service: ServiceType::WhatsApp,
            rawCostToPingly: $realCost,
            billedAmountToClient: $billed,
            multiplierOrMargin: $margin,
            metadata: ['category' => $category, 'country' => $country],
        );
    }
}
