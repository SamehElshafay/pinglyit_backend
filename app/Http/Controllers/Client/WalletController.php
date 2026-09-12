<?php

namespace App\Http\Controllers\Client;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function show(Request $request)
    {
        $company = $request->user()->company;

        $debits = $company->usageEvents()->latest()->limit(25)->get()
            ->map(fn ($e) => ['time' => $e->created_at, 'type' => $e->service_type->label(), 'amount' => -1 * (float) $e->billed_amount_to_client]);

        $credits = $company->walletAdjustments()->latest()->limit(25)->get()
            ->map(fn ($a) => ['time' => $a->created_at, 'type' => 'Adjustment', 'amount' => (float) $a->amount]);

        $topups = $company->walletTopups()->where('status', 'completed')->latest()->limit(25)->get()
            ->map(fn ($t) => ['time' => $t->created_at, 'type' => 'Top-up', 'amount' => (float) $t->amount]);

        $transactions = $debits->concat($credits)->concat($topups)
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
     * Start a top-up on whichever gateway is active. Returns a URL; the
     * frontend redirects the browser there. The wallet is only ever
     * credited from that gateway's webhook once it confirms payment, never
     * from this response.
     *
     * A gateway that isn't configured yet throws, and that surfaces as a
     * 501 carrying its own reason — the frontend shows it verbatim, so
     * "not configured" reaches the screen instead of a dead button.
     */
    public function topup(Request $request)
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);
        $company = $request->user()->company;

        try {
            $url = $this->gateway->createTopupSession($company, $data['amount'], $company->wallet->currency ?? 'USD');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 501);
        }

        return response()->json(['checkout_url' => $url]);
    }
}
