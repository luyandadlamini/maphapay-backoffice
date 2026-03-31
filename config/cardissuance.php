<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Card Issuance Configuration
    |--------------------------------------------------------------------------
    |
    | This file configures the virtual card issuance system for tap-to-pay
    | functionality via Apple Pay and Google Pay.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Card Issuer
    |--------------------------------------------------------------------------
    |
    | The card issuer provider to use. Currently: "demo".
    | Add a local bank adapter here when the bank partnership is finalised.
    |
    */

    'default_issuer' => env('CARD_ISSUER', 'demo'),

    'webhook_secret' => env('CARD_ISSUER_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Issuer Configurations
    |--------------------------------------------------------------------------
    */

    'issuers' => [
        'demo' => [
            'driver'   => 'demo',
            // ISO 4217 currency code for demo transactions and balances.
            // Default: SZL (Swazi Lilangeni). Set CARD_DEMO_CURRENCY=ZAR or USD as needed.
            'currency' => env('CARD_DEMO_CURRENCY', 'SZL'),
        ],

        // Future: add 'local_bank' => [...] when the bank partnership is finalised.
    ],

    /*
    |--------------------------------------------------------------------------
    | JIT Funding Configuration
    |--------------------------------------------------------------------------
    |
    | Just-in-Time funding settings for real-time card authorization.
    |
    */

    'jit_funding' => [
        // Maximum latency budget for authorization (milliseconds)
        'latency_budget_ms' => env('CARD_AUTH_LATENCY_BUDGET', 2000),

        // Default stablecoin for funding card transactions
        'default_token' => env('CARD_FUNDING_TOKEN', 'USDC'),

        // Supported tokens for card funding
        'supported_tokens' => ['USDC', 'USDT', 'DAI'],

        // Whether to allow partial approvals
        'allow_partial_approval' => env('CARD_ALLOW_PARTIAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Card Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        // Maximum cards per user
        'max_cards_per_user' => env('CARD_MAX_PER_USER', 5),

        // Default daily spending limit (in USD cents)
        'default_daily_limit' => env('CARD_DAILY_LIMIT', 500000), // $5,000

        // Default per-transaction limit (in USD cents)
        'default_transaction_limit' => env('CARD_TRANSACTION_LIMIT', 100000), // $1,000
    ],

    /*
    |--------------------------------------------------------------------------
    | Apple Pay / Google Pay Configuration
    |--------------------------------------------------------------------------
    */

    'wallet_provisioning' => [
        'apple_pay' => [
            'enabled'          => env('APPLE_PAY_ENABLED', true),
            'certificate_path' => env('APPLE_PAY_CERTIFICATE_PATH'),
            'key_path'         => env('APPLE_PAY_KEY_PATH'),
            'merchant_id'      => env('APPLE_PAY_MERCHANT_ID'),
        ],

        'google_pay' => [
            'enabled'          => env('GOOGLE_PAY_ENABLED', true),
            'wallet_issuer_id' => env('GOOGLE_PAY_WALLET_ISSUER_ID'),
            'backend_url'      => env('GOOGLE_PAY_BACKEND_URL'),
        ],
    ],
];
