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
    | Wallet top-up payment gateway (docs §4.7 — open decision, not chosen yet)
    |--------------------------------------------------------------------------
    */
    'payment_gateway' => env('PAYMENT_GATEWAY', 'none'),

];
