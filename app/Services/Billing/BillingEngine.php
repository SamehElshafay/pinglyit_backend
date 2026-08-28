<?php

namespace App\Services\Billing;

use App\Enums\ServiceType;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\UsageEvent;
use App\Models\WalletAdjustment;
use Illuminate\Support\Facades\DB;

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
}
