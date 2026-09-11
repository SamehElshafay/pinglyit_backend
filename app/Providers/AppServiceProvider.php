<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Payments\CryptomusGateway;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\TapGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The only place that knows which concrete gateway is active —
        // WalletController and the webhook controllers all resolve
        // PaymentGateway, never a concrete gateway class directly.
        $this->app->bind(PaymentGateway::class, match (config('pingly.payment_gateway')) {
            'stripe' => StripeGateway::class,
            'tap' => TapGateway::class,
            'cryptomus' => CryptomusGateway::class,
            default => CryptomusGateway::class,
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
