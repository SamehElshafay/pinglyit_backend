<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Payments\StripeGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The only place that knows which concrete gateway is active —
        // WalletController and StripeWebhookController both resolve
        // PaymentGateway, never StripeGateway directly.
        $this->app->bind(PaymentGateway::class, match (config('pingly.payment_gateway')) {
            'stripe' => StripeGateway::class,
            default => StripeGateway::class, // only one implemented so far
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
