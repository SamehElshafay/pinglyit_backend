<?php

namespace App\Contracts;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Whatever gateway is active behind `config('pingly.payment_gateway')`
 * implements this — WalletController never talks to Stripe (or Paymob,
 * or Fawry, whenever one of those gets added) directly, so swapping or
 * adding a gateway later touches this contract's implementations only.
 */
interface PaymentGateway
{
    /**
     * Start a top-up. Returns the URL to send the client to (a hosted
     * checkout page) to actually enter card details.
     */
    public function createTopupSession(Company $company, float $amount, string $currency): string;

    /**
     * Handle the gateway's async confirmation callback. Verifies the
     * request is genuinely from the provider, then credits the wallet via
     * BillingEngine::creditTopup() once payment is confirmed.
     */
    public function handleWebhook(Request $request): Response;
}
