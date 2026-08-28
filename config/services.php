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

    // Wallet top-ups, take two: Tap's onboarding needs a bank account we
    // don't have yet, so Paymob (Egyptian, CBE-licensed, accepted an
    // individual account with just a national ID + IBAN — no commercial
    // register needed at this volume) is the one actually reachable today.
    'paymob' => [
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
    ],

];
