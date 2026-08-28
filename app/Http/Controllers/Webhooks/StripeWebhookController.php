<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Public route (no JWT — Stripe isn't logged in). Trust comes entirely
 * from the Stripe-Signature header, verified inside the gateway.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Request $request)
    {
        return $this->gateway->handleWebhook($request);
    }
}
