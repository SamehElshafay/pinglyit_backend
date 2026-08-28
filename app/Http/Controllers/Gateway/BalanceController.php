<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * GET /v1/balance — what "each client gets their own API that shows their
 * remaining balance" means literally. No multiplier, no internal math,
 * just the number that's actually theirs to watch.
 */
class BalanceController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->attributes->get('company');
        $wallet = $company->wallet;

        return response()->json([
            'balance' => (float) ($wallet->balance ?? 0),
            'currency' => $wallet->currency ?? 'USD',
        ]);
    }
}
