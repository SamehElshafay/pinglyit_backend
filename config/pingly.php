<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Gateway — Meta Cloud API (docs §3.4)
    |--------------------------------------------------------------------------
    | Blank until a real Meta app + WABA exist. WhatsAppGatewayService checks
    | isConfigured() before calling out and throws a clear error otherwise.
    */
    'whatsapp' => [
        'app_id' => env('META_WHATSAPP_APP_ID'),
        'app_secret' => env('META_WHATSAPP_APP_SECRET'),
        'access_token' => env('META_WHATSAPP_ACCESS_TOKEN'),
        'api_version' => env('META_WHATSAPP_API_VERSION', 'v21.0'),
        // Whatever string you set here also goes into Meta's webhook config
        // screen (Verify Token) — Meta echoes it back on setup to prove it's
        // really you configuring the endpoint.
        'webhook_verify_token' => env('META_WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Gateway — OpenRouter (docs §4.1)
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'openrouter_api_key' => env('OPENROUTER_API_KEY'),
        'openrouter_api_base' => env('OPENROUTER_API_BASE', 'https://openrouter.ai/api/v1'),
        // Default token multiplier applied when a client has no override (docs §4.3).
        'default_multiplier' => (float) env('AI_DEFAULT_MULTIPLIER', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet top-up payment gateway (docs §4.7 — decided: Gulf-focused, so
    | 'tap' — Stripe doesn't support an Egypt-based payout account, and most
    | revenue here is Saudi/UAE anyway; see AppServiceProvider for the bind)
    |--------------------------------------------------------------------------
    | 'none' disables top-ups entirely (WalletController falls back to a 501).
    */
    'payment_gateway' => env('PAYMENT_GATEWAY', 'tap'),

    // Where to send the client back after a hosted checkout session (the
    // user_website dashboard, not the API's own URL).
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5184'),

    /*
    |--------------------------------------------------------------------------
    | Billing safety cap
    |--------------------------------------------------------------------------
    | A hard ceiling, in dollars, on what a *single* usage event can debit
    | from a wallet — see BillingEngine::recordUsage(). Exists so a pricing
    | mistake (wrong multiplier, bad price lookup, a future service that gets
    | its math wrong) caps out instead of silently draining real money from a
    | client. Set to 0 to disable (not recommended).
    */
    'max_billed_per_event' => (float) env('MAX_BILLED_PER_EVENT', 5.00),

];
