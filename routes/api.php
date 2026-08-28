<?php

use App\Http\Controllers\Admin\AiLogController;
use App\Http\Controllers\Admin\AiPricingController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\WalletAdjustmentController;
use App\Http\Controllers\Admin\WhatsappLogController;
use App\Http\Controllers\Admin\WhatsappPricingController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Client\ApiKeyController;
use App\Http\Controllers\Client\OverviewController as ClientOverviewController;
use App\Http\Controllers\Client\ServiceController;
use App\Http\Controllers\Client\WalletController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Webhooks\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks — public, no JWT. Each verifies its own caller (Stripe via
| signature header, Meta via the verify-token handshake).
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
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

        Route::get('/ai/pricing', [AiPricingController::class, 'show']);
        Route::put('/ai/pricing', [AiPricingController::class, 'update']);
        Route::get('/ai/logs', [AiLogController::class, 'index']);

        Route::get('/billing/reconciliation', [ReconciliationController::class, 'index']);
        Route::get('/wallet-adjustments', [WalletAdjustmentController::class, 'index']);
        Route::post('/clients/{company}/wallet-adjustments', [WalletAdjustmentController::class, 'store']);
    });
});
