<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\CryptomusGateway;
use Illuminate\Http\Request;

/**
 * Public route (no JWT — Cryptomus isn't logged in). Trust comes from the
 * `sign` field inside the webhook body, verified inside the gateway.
 *
 * Bound to the concrete CryptomusGateway, not the PaymentGateway interface
 * — see StripeWebhookController's docblock for why.
 */
class CryptomusWebhookController extends Controller
{
    public function __construct(private readonly CryptomusGateway $gateway) {}

    public function handle(Request $request)
    {
        return $this->gateway->handleWebhook($request);
    }
}
