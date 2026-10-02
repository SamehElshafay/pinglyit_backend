<?php

namespace App\Services\WhatsApp;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\Billing\BillingEngine;
use App\Services\Billing\ServiceConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    /**
     * True when the gateway can send for *someone* — either Pingly holds a
     * platform-wide token, or at least one client has connected their own
     * WhatsApp Business Account. Per-company readiness is a different
     * question, answered by isReadyFor().
     */
    public function isConfigured(): bool
    {
        return filled(config('pingly.whatsapp.access_token'))
            || WhatsappAccount::whereNotNull('access_token')->exists();
    }

    /** This company specifically has a number that can send right now. */
    public function isReadyFor(Company $company): bool
    {
        return $this->connectedAccountFor($company) !== null;
    }

    public function connectedAccountFor(Company $company): ?WhatsappAccount
    {
        return $company->whatsappAccounts()
            ->where('status', 'connected')
            ->whereNotNull('phone_number_id')
            ->first();
    }

    /**
     * The client's own Meta token when they've connected their own WhatsApp
     * Business Account, falling back to Pingly's platform token for numbers
     * connected under Pingly's account before per-client tokens existed.
     *
     * The client's own token is what makes this product possible at all:
     * an unverified business is capped by Meta at two phone numbers across
     * all its WABAs, so hosting every client under one account stops being
     * a product at the second customer.
     */
    private function tokenFor(WhatsappAccount $account): ?string
    {
        return $account->access_token ?: config('pingly.whatsapp.access_token');
    }

    /**
     * Ask Meta whether this phone number id + token pair actually works,
     * before anything is stored.
     *
     * Worth the round trip because the most common mistake here is pasting
     * the temporary token Meta shows on its own test screen, which expires
     * in 24 hours. Saved blind, that looks like a successful setup today and
     * fails silently tomorrow on a real customer's message — with nothing to
     * point at. Checking now turns that into a sentence on the setup screen.
     *
     * @return array{ok: bool, message: string, phone_number: ?string}
     */
    public function verifyCredentials(string $phoneNumberId, string $token): array
    {
        $apiVersion = config('pingly.whatsapp.api_version');

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->get("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}", [
                    'fields' => 'display_phone_number,verified_name',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('WhatsApp credential check could not reach Meta.', ['reason' => $e->getMessage()]);

            return ['ok' => false, 'message' => "Couldn't reach WhatsApp to check these details — try again in a moment.", 'phone_number' => null];
        }

        if ($response->successful()) {
            return [
                'ok' => true,
                'message' => 'Connected.',
                'phone_number' => $response->json('display_phone_number'),
            ];
        }

        // Meta's own error body names the vendor and can carry account
        // detail, so it goes to the log. What comes back is the thing the
        // client can actually act on.
        Log::warning('WhatsApp credential check rejected by Meta.', [
            'phone_number_id' => $phoneNumberId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $code = (int) $response->json('error.code');

        $message = match (true) {
            $code === 190 => 'That access token is expired or invalid. Make sure you created a permanent System User token, not the temporary one shown on the test screen.',
            $response->status() === 404 => "That Phone number ID doesn't exist, or this token can't see it. Check you copied the Phone number ID and not the WhatsApp Business Account ID.",
            default => 'WhatsApp rejected these details. Check the Phone number ID and token, then try again.',
        };

        return ['ok' => false, 'message' => $message, 'phone_number' => null];
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
    public function send(Company $company, string $to, string $category, string $country, array $payload, string $sentBy = 'api'): array
    {
        $estimatedBilled = $this->estimateCost($company, $category, $country);

        if (! $this->billing->hasSufficientBalance($company, $estimatedBilled)) {
            throw new RuntimeException('Wallet balance is too low to send this message.');
        }

        $account = $this->connectedAccountFor($company);
        if (! $account) {
            throw new RuntimeException('No WhatsApp number is connected to this account yet.');
        }

        $token = $this->tokenFor($account);
        if (blank($token)) {
            throw new RuntimeException('This WhatsApp number has no access token — reconnect it from the dashboard.');
        }

        $apiVersion = config('pingly.whatsapp.api_version');
        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->post("https://graph.facebook.com/{$apiVersion}/{$account->phone_number_id}/messages", array_merge([
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                ], $payload));
        } catch (ConnectionException $e) {
            throw new RuntimeException("Couldn't reach Meta's Cloud API: {$e->getMessage()}");
        }

        if ($response->failed()) {
            // Meta's body names the vendor and can carry account detail the
            // client shouldn't see — same rule as the AI Gateway's upstream
            // errors. The real reason goes to the log, not the response.
            Log::error('WhatsApp upstream send failed.', [
                'company_id' => $company->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            WhatsappMessage::record([
                'company_id' => $company->id,
                'customer_phone' => $to,
                'direction' => WhatsappMessage::DIRECTION_OUT,
                'type' => $payload['type'] ?? 'text',
                'body' => $payload['text']['body'] ?? null,
                'status' => 'failed',
                // Meta's own wording, kept out of the client-facing message
                // above but worth having on the row someone is debugging.
                'error' => $response->json('error.message') ?? 'Upstream rejected the message.',
                'sent_by' => $sentBy,
            ]);

            throw new RuntimeException('The message could not be sent — check the number is still connected, then try again.');
        }

        $this->recordDeliveredMessage($company, $category, $country, $this->baseCostFor($company, $category, $country));

        WhatsappMessage::record([
            'company_id' => $company->id,
            'customer_phone' => $to,
            'direction' => WhatsappMessage::DIRECTION_OUT,
            'type' => $payload['type'] ?? 'text',
            'body' => $payload['text']['body'] ?? null,
            'wa_message_id' => $response->json('messages.0.id'),
            'status' => 'sent',
            'sent_by' => $sentBy,
        ]);

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
