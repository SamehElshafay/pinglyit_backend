<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WalletController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->user()->company;

        $debits = $company->usageEvents()->latest()->limit(25)->get()
            ->map(fn ($e) => ['time' => $e->created_at, 'type' => $e->service_type->label(), 'amount' => -1 * (float) $e->billed_amount_to_client]);

        $credits = $company->walletAdjustments()->latest()->limit(25)->get()
            ->map(fn ($a) => ['time' => $a->created_at, 'type' => 'Adjustment', 'amount' => (float) $a->amount]);

        $transactions = $debits->concat($credits)
            ->sortByDesc('time')
            ->values()
            ->take(25);

        return response()->json([
            'balance' => (float) ($company->wallet->balance ?? 0),
            'currency' => $company->wallet->currency ?? 'USD',
            'transactions' => $transactions,
        ]);
    }

    /**
     * Wallet top-up — no payment gateway is chosen yet (docs §4.7, open
     * decision), so this stays a 501 until PAYMENT_GATEWAY is set to
     * something real and a matching gateway client is wired in here.
     */
    public function topup(Request $request)
    {
        $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'payment_method' => ['required', 'string']]);

        if (config('pingly.payment_gateway') === 'none') {
            return response()->json([
                'message' => 'No payment gateway is configured yet — set PAYMENT_GATEWAY in .env once one is chosen.',
            ], 501);
        }

        // TODO: create a charge/checkout session with the configured gateway
        // (Paymob/Fawry/Stripe/...), then credit the wallet via BillingEngine
        // once the gateway confirms payment (webhook, not this response).
        abort(501, 'Payment gateway integration not implemented yet.');
    }
}
