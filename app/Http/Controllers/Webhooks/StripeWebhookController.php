<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\StripeGateway;
use Illuminate\Http\Request;

/**
 * Public route (no JWT — Stripe isn't logged in). Trust comes entirely
 * from the Stripe-Signature header, verified inside the gateway.
 *
 * Bound to the concrete StripeGateway, deliberately not the PaymentGateway
 * interface — this URL is only ever hit by Stripe, regardless of which
 * gateway is the *active* one in config('pingly.payment_gateway'). Compare
 * TapWebhookController, same reasoning.
 */
class StripeWebhookController extends Controller
{
    public function __construct(private readonly StripeGateway $gateway) {}

    public function handle(Request $request)
    {
        return $this->gateway->handleWebhook($request);
    }
}
