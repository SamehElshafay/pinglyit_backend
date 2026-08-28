<?php

namespace App\Services\WhatsApp;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
     * The raw cost-to-Pingly for one category/country, straight from the
     * base cost table — no margin applied yet.
     */
    private function baseCostFor(Company $company, string $category, string $country): float
    {
        $pricing = $this->pricingFor($company);
        $base = collect($pricing['base_costs'] ?? [])
            ->first(fn ($row) => $row['category'] === $category && $row['country'] === $country);

        if (! $base) {
            throw new RuntimeException("No base cost configured for {$category}/{$country} — add it to the WhatsApp pricing config first.");
        }

        return (float) $base['cost'];
    }

    /**
     * cost-to-Pingly + margin, for one message category/country — what the
     * client is actually billed. Same math the admin's pricing screen uses.
     */
    public function estimateCost(Company $company, string $category, string $country): float
    {
        $pricing = $this->pricingFor($company);
        $margin = (float) ($pricing['margin_percent'] ?? 0);

        return round($this->baseCostFor($company, $category, $country) * (1 + $margin / 100), 6);
    }

    /**
     * Send one message via Meta's Cloud API. Pre-flight balance check
     * against the estimated bill, then the real call, then records the
     * billable event using our own known base cost (Meta doesn't return
     * per-message cost synchronously — that only shows up in Meta's own
     * billing reports, so our admin-configured base cost table is the
     * source of truth here, same as the pricing screen already assumes).
     *
     * @param  array<string, mixed>  $payload  the message body — merged into the Cloud API request as-is (e.g. `['type' => 'text', 'text' => ['body' => '...']]`)
     */
    public function send(Company $company, string $to, string $category, string $country, array $payload): array
    {
        $estimatedBilled = $this->estimateCost($company, $category, $country);

        if (! $this->billing->hasSufficientBalance($company, $estimatedBilled)) {
            throw new RuntimeException('Wallet balance is too low to send this message.');
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('WhatsApp Gateway is not configured — set META_WHATSAPP_* in .env.');
        }

        $account = $company->whatsappAccounts()->where('status', 'connected')->whereNotNull('phone_number_id')->first();
        if (! $account) {
            throw new RuntimeException('This company has no connected WhatsApp number.');
        }

        $apiVersion = config('pingly.whatsapp.api_version');
        try {
            $response = Http::withToken(config('pingly.whatsapp.access_token'))
                ->timeout(30)
                ->post("https://graph.facebook.com/{$apiVersion}/{$account->phone_number_id}/messages", array_merge([
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                ], $payload));
        } catch (ConnectionException $e) {
            throw new RuntimeException("Couldn't reach Meta's Cloud API: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new RuntimeException('Meta Cloud API error: '.$response->body());
        }

        $this->recordDeliveredMessage($company, $category, $country, $this->baseCostFor($company, $category, $country));

        return $response->json();
    }

    /**
     * The actual billing step — raw cost in, margin applied here, wallet
     * debited. Called from send() above at request time, or again from
     * the webhook if a delivery receipt ever needs to correct the figure.
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
