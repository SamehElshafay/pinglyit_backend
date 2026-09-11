<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Wallet top-ups — kept working in case a Stripe-eligible entity ever
    // exists, but Stripe doesn't support Egypt-based payout accounts, so
    // Tap (below) is the one that's actually reachable for this business.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Wallet top-ups (docs §4.7 — decided: Gulf-focused, most revenue from
    // Saudi/UAE) — Tap covers cards + Mada across the GCC in one integration.
    'tap' => [
        'secret_key' => env('TAP_SECRET_KEY'),
        'publishable_key' => env('TAP_PUBLISHABLE_KEY'),
    ],

    // Wallet top-ups, no bank account needed at all — cards/EGP not
    // required, just a KYC'd merchant account + domain confirmation.
    // Switch PAYMENT_GATEWAY=cryptomus to use these instead.
    'cryptomus' => [
        'merchant_id' => env('CRYPTOMUS_MERCHANT_ID'),
        'api_key' => env('CRYPTOMUS_API_KEY'), // the *Payment* API key, not Payout
    ],

    // Wallet top-ups, take two: Tap's onboarding needs a bank account we
    // don't have yet, so Paymob (Egyptian, CBE-licensed, accepted an
    // individual account with just a national ID + IBAN — no commercial
    // register needed at this volume) is the one actually reachable today.
    'paymob' => [
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        // The numeric Integration ID for the "Online Card" method (dashboard →
        // Developers → Payment Integrations) — account-specific, not a secret,
        // but still admin-managed for the same reason the keys above are.
        'integration_id' => env('PAYMOB_INTEGRATION_ID'),
        // This account's Integration ID is fixed to EGP, but the wallet is
        // USD — see PaymobGateway::usdToEgpRate()'s docblock for why the
        // conversion happens only at this one boundary, admin-set here.
        'usd_to_egp_rate' => env('PAYMOB_USD_TO_EGP_RATE'),
    ],

    // "Sign in with Google" for user_website (docs: GoogleAuthService).
    // Just a Client ID — Google designs it to be public/embedded in
    // frontend JS, no client secret involved in this flow at all.
    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
    ],

];
