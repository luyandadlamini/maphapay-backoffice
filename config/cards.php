<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Card processor (see also config/cardissuance.php)
    |--------------------------------------------------------------------------
    */
    'default_processor' => env('CARDS_DEFAULT_PROCESSOR', 'demo'),

    'processors' => [
        'demo'   => [],
        'rain'   => [],
        'stripe' => [
            'secret_key'      => env('STRIPE_SECRET'),
            'publishable_key' => env('STRIPE_KEY'),
            'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET'),
            'fx_rate_usd_szl' => (float) env('STRIPE_FX_RATE_USD_SZL', 18.50),
            'api_version'     => '2026-04-22.dahlia',
        ],
    ],

    'reveal' => [
        'ttl_seconds' => (int) env('CARDS_REVEAL_TTL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk thresholds (see docs/cards/01-product-config.md §9)
    |--------------------------------------------------------------------------
    |
    | *_deny_at / *_block_at values are inclusive counts (e.g. 6 declines in 10m).
    |
    */
    'risk' => [
        'declines_10min_deny_at'                    => 6,
        'declines_24h_deny_at'                      => 11,
        'distinct_declined_merchants_30min_deny_at' => 3,
        'replacements_30d_block_at'                 => 3,
        'disputes_60d_review_at'                    => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked MCC codes (merged with per-card user blocks at runtime)
    |--------------------------------------------------------------------------
    |
    | @var array<int, string>  Four-digit MCC strings
    */
    'blocked_mccs' => [],
];
