<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymobGateway;
use Illuminate\Http\Request;

/**
 * Public route (no JWT — Paymob isn't logged in). Trust comes from the
 * `hmac` query parameter, verified inside the gateway.
 *
 * Bound to the concrete PaymobGateway, not the PaymentGateway interface —
 * see StripeWebhookController's docblock for why.
 */
class PaymobWebhookController extends Controller
{
    public function __construct(private readonly PaymobGateway $gateway) {}

    public function handle(Request $request)
    {
        return $this->gateway->handleWebhook($request);
    }
}
