<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\TapGateway;
use Illuminate\Http\Request;

/**
 * Public route (no JWT — Tap isn't logged in). Trust comes from the
 * `hashstring` header, verified inside the gateway.
 *
 * Bound to the concrete TapGateway, not the PaymentGateway interface — see
 * StripeWebhookController's docblock for why.
 */
class TapWebhookController extends Controller
{
    public function __construct(private readonly TapGateway $gateway) {}

    public function handle(Request $request)
    {
        return $this->gateway->handleWebhook($request);
    }
}
