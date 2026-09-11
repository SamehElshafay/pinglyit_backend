<?php

namespace App\Services\Billing;

use App\Enums\ServiceType;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\UsageEvent;
use App\Models\WalletAdjustment;
use App\Models\WalletTopup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place every service's usage turns into a wallet debit. A new
 * service doesn't touch this class — it just calls recordUsage() with its
 * own service_type and metadata shape (docs §5 / §6: "registers itself
 * into the shared billing engine — not hardcoded into it").
 */
class BillingEngine
{
    /**
     * Pre-flight check a service should make before doing anything that
     * costs Pingly money (e.g. before forwarding to Meta/OpenRouter).
     */
    public function hasSufficientBalance(Company $company, float $estimatedCost): bool
    {
        $wallet = $company->wallet;

        return $wallet !== null && (float) $wallet->balance >= $estimatedCost;
    }

    /**
     * Log one billable event and debit the wallet for it, atomically.
     * Called after the real cost is known (e.g. after Meta/OpenRouter has
     * already responded) — so it always records the event even if it pushes
     * the balance negative; the pre-flight check above is what prevents
     * that in the common case.
     *
     * Hard ceiling per single event (config('pingly.max_billed_per_event'),
     * default $5): whatever a service computes, this is the one place that
     * amount actually reaches the wallet, so it's the one place a mistake
     * anywhere upstream — this service's math, a bad price lookup, a future
     * service that gets it wrong — can be stopped before a client actually
     * loses real money to it. Tripping it never blocks the request; it caps
     * the charge and logs loudly so it gets caught, not silently eaten.
     *
     * @param  array<string, mixed>  $metadata  service-specific detail (category/country, model/tokens, etc.)
     */
    public function recordUsage(
        Company $company,
        ServiceType $service,
        float $rawCostToPingly,
        float $billedAmountToClient,
        ?float $multiplierOrMargin = null,
        array $metadata = [],
    ): UsageEvent {
        $cap = (float) config('pingly.max_billed_per_event');

        if ($cap > 0 && $billedAmountToClient > $cap) {
            Log::critical('Billing safety cap triggered — charge capped, does not reflect the raw calculation', [
                'company_id' => $company->id,
                'service_type' => $service->value,
                'calculated_amount' => $billedAmountToClient,
                'capped_at' => $cap,
                'metadata' => $metadata,
            ]);

            $metadata['billing_cap_triggered'] = true;
            $metadata['uncapped_amount'] = $billedAmountToClient;
            $billedAmountToClient = $cap;
        }

        return DB::transaction(function () use ($company, $service, $rawCostToPingly, $billedAmountToClient, $multiplierOrMargin, $metadata) {
            $wallet = $company->wallet()->lockForUpdate()->firstOrFail();
            $wallet->decrement('balance', $billedAmountToClient);

            return UsageEvent::create([
                'company_id' => $company->id,
                'service_type' => $service,
                'raw_cost_to_pingly' => $rawCostToPingly,
                'billed_amount_to_client' => $billedAmountToClient,
                'multiplier_or_margin_applied' => $multiplierOrMargin,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * A manual credit/debit from the admin dashboard — always logged with
     * who and why (docs: audit trail requirement).
     */
    public function adjustWallet(Company $company, AdminUser $admin, float $amount, string $reason): WalletAdjustment
    {
        return DB::transaction(function () use ($company, $admin, $amount, $reason) {
            $wallet = $company->wallet()->lockForUpdate()->firstOrFail();
            $wallet->increment('balance', $amount); // $amount is signed: negative = debit

            return WalletAdjustment::create([
                'company_id' => $company->id,
                'admin_user_id' => $admin->id,
                'amount' => $amount,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Record a top-up as pending *before* the charge happens, with an
     * amount Pingly itself decided (not anything a gateway will later
     * report). Exists because a gateway's webhook can't be fully trusted to
     * echo back what was actually requested — a field the docs promise will
     * round-trip may quietly not, so the credited amount is decided here
     * once and never re-read from the callback. `providerReference` must be
     * something *we* generate and control (e.g. CryptomusGateway's
     * `order_id`), not the gateway's own transaction id, since that isn't
     * known until the webhook fires.
     */
    public function recordPendingTopup(Company $company, float $amount, string $currency, string $provider, string $providerReference): WalletTopup
    {
        return WalletTopup::create([
            'company_id' => $company->id,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
        ]);
    }

    /**
     * Credit a real-money top-up (Cryptomus, Stripe, Tap — whatever gateway
     * is active). Idempotent on `providerReference` — a webhook retry for
     * the same reference will not double-credit the wallet.
     *
     * The amount actually credited always comes from the WalletTopup row's
     * own stored `amount`, never the `$amount` parameter directly — for a
     * gateway that never pre-records one (Stripe/Tap: no existing row, so
     * firstOrCreate makes one right here from $amount/$currency), that's
     * the same value either way. For one that does (Cryptomus: see
     * recordPendingTopup()), the row's already-stored, Pingly-decided
     * amount wins over anything the gateway's webhook claims — the
     * $amount/$currency arguments are then just what the caller *thinks*
     * it should be, used only if no pending row already exists.
     */
    public function creditTopup(Company $company, float $amount, string $currency, string $provider, string $providerReference): WalletTopup
    {
        return DB::transaction(function () use ($company, $amount, $currency, $provider, $providerReference) {
            $topup = WalletTopup::query()->lockForUpdate()->firstOrCreate(
                ['provider' => $provider, 'provider_reference' => $providerReference],
                ['company_id' => $company->id, 'amount' => $amount, 'currency' => $currency, 'status' => 'pending'],
            );

            if ($topup->status === 'completed') {
                return $topup; // already credited — webhook fired more than once
            }

            $company->wallet()->lockForUpdate()->increment('balance', $topup->amount);
            $topup->update(['status' => 'completed']);

            return $topup;
        });
    }
}
