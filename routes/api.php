<?php

use App\Http\Controllers\Admin\AiConnectionController;
use App\Http\Controllers\Admin\AiLogController;
use App\Http\Controllers\Admin\AiPricingController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Admin\PaymentConnectionController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\WalletAdjustmentController;
use App\Http\Controllers\Admin\WhatsappLogController;
use App\Http\Controllers\Admin\WhatsappPricingController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Client\ApiKeyController;
use App\Http\Controllers\Client\OverviewController as ClientOverviewController;
use App\Http\Controllers\Client\ServiceController;
use App\Http\Controllers\Client\WalletController;
use App\Http\Controllers\Gateway\AiController as GatewayAiController;
use App\Http\Controllers\Gateway\BalanceController as GatewayBalanceController;
use App\Http\Controllers\Gateway\WhatsAppController as GatewayWhatsAppController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Webhooks\TapWebhookController;
use App\Http\Controllers\Webhooks\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public gateway API (/v1) — this is the actual product: a client's own
| project calls this directly with the pk_live_ key it created on the API
| keys screen. Nothing to do with logging a person into a dashboard — see
| AuthenticateApiKey. Every call here debits the caller's own wallet.
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware(['api-key', 'throttle:60,1'])->group(function () {
    Route::get('/balance', [GatewayBalanceController::class, 'show']);
    Route::get('/models', [GatewayAiController::class, 'models']);
    Route::post('/ai/chat', [GatewayAiController::class, 'chat']);
    Route::post('/whatsapp/send', [GatewayWhatsAppController::class, 'send']);
});

/*
|--------------------------------------------------------------------------
| Webhooks — public, no JWT. Each verifies its own caller (Stripe/Tap via
| a signature header, Meta via the verify-token handshake). Both payment
| webhooks stay registered regardless of which one is the *active* gateway
| — see the controllers' docblocks for why they bind concretely, not via
| the PaymentGateway interface.
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
Route::post('/webhooks/tap', [TapWebhookController::class, 'handle']);
Route::get('/webhooks/whatsapp', [WhatsappWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsappWebhookController::class, 'receive']);

/*
|--------------------------------------------------------------------------
| Client-facing auth (user_website) — no prefix, this is the "default" API
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [RegisterController::class, 'store']);
    Route::post('/login', [LoginController::class, 'store']);
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

Route::middleware(['jwt', 'client'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy']);
    Route::get('/me', [LoginController::class, 'me']);
    Route::put('/account', [LoginController::class, 'update']);

    Route::get('/overview', [ClientOverviewController::class, 'index']);

    Route::get('/wallet', [WalletController::class, 'show']);
    Route::post('/wallet/topup', [WalletController::class, 'topup']);

    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/whatsapp', [ServiceController::class, 'whatsapp']);
    Route::post('/services/whatsapp/send', [ServiceController::class, 'sendWhatsapp']);
    Route::get('/services/ai', [ServiceController::class, 'ai']);
    Route::get('/services/ai/models', [ServiceController::class, 'aiModels']);
    Route::post('/services/ai/chat', [ServiceController::class, 'chatAi']);

    Route::get('/api-keys', [ApiKeyController::class, 'index']);
    Route::post('/api-keys', [ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Admin (admin_website) — every route needs the shared admin role
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'store'])->middleware('throttle:10,1');

    Route::middleware(['jwt', 'admin'])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'destroy']);
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::put('/profile', [AdminAuthController::class, 'update']);

        Route::get('/overview', [AdminOverviewController::class, 'index']);

        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/{company}', [ClientController::class, 'show']);
        Route::get('/clients/{company}/api-keys', [ClientController::class, 'apiKeys']);
        Route::put('/clients/{company}/whatsapp-config', [ClientController::class, 'updateWhatsappConfig']);
        Route::put('/clients/{company}/ai-config', [ClientController::class, 'updateAiConfig']);

        Route::get('/whatsapp/pricing', [WhatsappPricingController::class, 'show']);
        Route::put('/whatsapp/pricing', [WhatsappPricingController::class, 'update']);
        Route::get('/whatsapp/logs', [WhatsappLogController::class, 'index']);

        Route::get('/ai/models', [AiPricingController::class, 'models']);
        Route::get('/ai/pricing', [AiPricingController::class, 'show']);
        Route::put('/ai/pricing', [AiPricingController::class, 'update']);
        Route::get('/ai/logs', [AiLogController::class, 'index']);
        Route::get('/ai/connection', [AiConnectionController::class, 'show']);
        Route::put('/ai/connection', [AiConnectionController::class, 'update']);
        Route::delete('/ai/connection', [AiConnectionController::class, 'destroy']);

        Route::get('/billing/reconciliation', [ReconciliationController::class, 'index']);
        Route::get('/wallet-adjustments', [WalletAdjustmentController::class, 'index']);
        Route::post('/clients/{company}/wallet-adjustments', [WalletAdjustmentController::class, 'store']);

        Route::get('/payment/connection', [PaymentConnectionController::class, 'show']);
        Route::put('/payment/connection', [PaymentConnectionController::class, 'update']);
        Route::delete('/payment/connection', [PaymentConnectionController::class, 'destroy']);

        Route::get('/audit-log', [AuditLogController::class, 'index']);
    });
});
