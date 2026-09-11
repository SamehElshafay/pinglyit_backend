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

    // Wallet top-ups — kept wired up in case a Stripe-eligible entity ever
    // exists, but its onboarding needs a bank account this business doesn't
    // have yet, so Cryptomus (below) is the one actually reachable today.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Wallet top-ups — Tap covers cards + Mada across the GCC in one
    // integration, parked for now because its onboarding needs a bank
    // account that doesn't exist yet. Kept ready to switch back to.
    'tap' => [
        'secret_key' => env('TAP_SECRET_KEY'),
        'publishable_key' => env('TAP_PUBLISHABLE_KEY'),
    ],

    // Wallet top-ups, the active gateway — no bank account needed at all,
    // just a KYC'd merchant account + domain confirmation, and it settles
    // in USD so no currency conversion happens anywhere in the app.
    'cryptomus' => [
        'merchant_id' => env('CRYPTOMUS_MERCHANT_ID'),
        'api_key' => env('CRYPTOMUS_API_KEY'), // the *Payment* API key, not Payout
    ],

    // "Sign in with Google" for user_website (docs: GoogleAuthService).
    // Just a Client ID — Google designs it to be public/embedded in
    // frontend JS, no client secret involved in this flow at all.
    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
    ],

];
