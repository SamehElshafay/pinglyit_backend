<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Company;
use App\Services\Billing\BillingEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Cards, international — Stripe Checkout (docs §4.7, decided). Checkout is
 * a Stripe-hosted page, so raw card numbers never touch this backend.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly BillingEngine $billing) {}

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    public function createTopupSession(Company $company, float $amount, string $currency): string
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Stripe is not configured — set STRIPE_KEY/STRIPE_SECRET in .env.');
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $session = Session::create([
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => ['name' => 'Pingly wallet top-up'],
                        'unit_amount' => (int) round($amount * 100), // Stripe wants the smallest currency unit
                    ],
                    'quantity' => 1,
                ]],
                'client_reference_id' => (string) $company->id,
                'metadata' => ['company_id' => $company->id],
                'success_url' => config('pingly.frontend_url').'/wallet?topup=success',
                'cancel_url' => config('pingly.frontend_url').'/wallet?topup=cancelled',
            ]);
        } catch (ApiConnectionException $e) {
            throw new \RuntimeException("Couldn't reach Stripe: {$e->getMessage()}");
        } catch (ApiErrorException $e) {
            throw new \RuntimeException("Stripe rejected the request: {$e->getMessage()}");
        }

        return $session->url;
    }

    public function handleWebhook(Request $request): Response
    {
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $webhookSecret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return response('Invalid signature', 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $companyId = $session->metadata->company_id ?? $session->client_reference_id ?? null;
            $company = $companyId ? Company::find($companyId) : null;

            if ($company && $session->payment_status === 'paid') {
                $this->billing->creditTopup(
                    company: $company,
                    amount: $session->amount_total / 100,
                    currency: strtoupper($session->currency),
                    provider: 'stripe',
                    providerReference: $session->id,
                );
            } else {
                Log::warning('Stripe checkout.session.completed with no resolvable/unpaid company', ['session_id' => $session->id ?? null]);
            }
        }

        // Stripe only cares that this returns 2xx quickly — it retries otherwise.
        return response('ok', 200);
    }
}
