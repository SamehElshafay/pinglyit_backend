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
        // The Embedded Signup flow's own Configuration ID — created once
        // Pingly is registered as a Meta Tech Provider (App Dashboard →
        // WhatsApp → Embedded Signup → Configurations), separate from the
        // App ID/Secret above. Not a secret (goes straight into the
        // frontend's FB.login() call, same as app_id) — see
        // WhatsAppEmbeddedSignupService's docblock.
        'config_id' => env('META_WHATSAPP_CONFIG_ID'),
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
    | Wallet top-up payment gateway
    |--------------------------------------------------------------------------
    | 'stripe' | 'tap' | 'paymob' | 'cryptomus' | 'none' (disables top-ups —
    | WalletController falls back to a 501). Originally Tap (Gulf-focused
    | revenue), but Tap's own onboarding needs a bank account that doesn't
    | exist yet; Paymob (Egyptian, accepted an individual account today) is
    | what's actually reachable right now — see AppServiceProvider for the
    | bind. Cryptomus (crypto/USDT) is the one option here that needs no
    | bank account at all — see CryptomusGateway's docblock. This is a
    | one-time deployment choice, not a secret, so it stays here rather than
    | in the admin-managed PlatformSetting store (each gateway's own API keys
    | still live there, encrypted — see AiConnectionController's docblock).
    */
    'payment_gateway' => env('PAYMENT_GATEWAY', 'paymob'),

    // Where to send the client back after a hosted checkout session (the
    // user_website dashboard, not the API's own URL).
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5184'),

    /*
    |--------------------------------------------------------------------------
    | Paymob (docs: see PaymobGateway's own docblock)
    |--------------------------------------------------------------------------
    | Regional API base — the merchant account this was built against is
    | Egypt-registered. Paymob also runs ksa.paymob.com / uae.paymob.com /
    | oman.paymob.com for merchant accounts opened in those countries; change
    | this if the account ever moves.
    */
    'paymob' => [
        // env('X', 'default') only falls back when the key is fully absent —
        // both .env and .env.example ship PAYMOB_API_BASE= blank (as a
        // discoverable override point), which env() treats as "set to ''",
        // not "use the default". `?:` catches that case too.
        'api_base' => env('PAYMOB_API_BASE') ?: 'https://accept.paymob.com',
    ],

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
