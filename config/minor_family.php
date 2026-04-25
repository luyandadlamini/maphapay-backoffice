<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Public funding link (sponsor) anti-abuse
    |--------------------------------------------------------------------------
    |
    | Rolling window limits for unauthenticated request-to-pay initiation.
    | Env overrides allow ops tuning without code changes.
    |
    */
    'public_funding' => [
        'attempt_window_minutes'          => (int) env('MINOR_FAMILY_PUBLIC_FUNDING_WINDOW_MINUTES', 10),
        'sponsor_max_attempts_per_window' => (int) env('MINOR_FAMILY_PUBLIC_FUNDING_SPONSOR_MAX_ATTEMPTS', 5),
        'link_max_attempts_per_window'    => (int) env('MINOR_FAMILY_PUBLIC_FUNDING_LINK_MAX_ATTEMPTS', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation exception queue (operator)
    |--------------------------------------------------------------------------
    */
    'reconciliation_exception' => [
        'sla_review_hours' => max(1, (int) env('MINOR_FAMILY_RECON_EXCEPTION_SLA_HOURS', 24)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Age Eligibility
    |--------------------------------------------------------------------------
    */
    'age_min'           => (int) env('MINOR_AGE_MIN', 6),
    'age_max'           => (int) env('MINOR_AGE_MAX', 17),
    'tier_grow_max_age' => (int) env('MINOR_TIER_GROW_MAX_AGE', 12),

    /*
    |--------------------------------------------------------------------------
    | Permission Levels
    |--------------------------------------------------------------------------
    */
    'permission_level_min'       => (int) env('MINOR_PERMISSION_LEVEL_MIN', 1),
    'permission_level_max_grow'  => (int) env('MINOR_PERMISSION_LEVEL_MAX_GROW', 4),
    'permission_level_max_rise'  => (int) env('MINOR_PERMISSION_LEVEL_MAX_RISE', 7),
    'permission_level_age_1_max' => (int) env('MINOR_PERMISSION_LEVEL_AGE_1_MAX', 7),
    'permission_level_age_2_max' => (int) env('MINOR_PERMISSION_LEVEL_AGE_2_MAX', 9),
    'permission_level_age_3_max' => (int) env('MINOR_PERMISSION_LEVEL_AGE_3_MAX', 11),
    'permission_level_age_4_max' => (int) env('MINOR_PERMISSION_LEVEL_AGE_4_MAX', 13),
    'permission_level_age_5_max' => (int) env('MINOR_PERMISSION_LEVEL_AGE_5_MAX', 15),
    'permission_level_default'   => (int) env('MINOR_PERMISSION_LEVEL_DEFAULT', 6),

    /*
    |--------------------------------------------------------------------------
    | Spending Limits (minor units — ZAR cents unless noted)
    |--------------------------------------------------------------------------
    | Each level is a [daily, monthly] pair parsed from a comma-separated string.
    */
    'spend_limit_level_1' => array_map('intval', explode(',', (string) env('MINOR_SPEND_LIMIT_L1', '50000,500000'))),
    'spend_limit_level_2' => array_map('intval', explode(',', (string) env('MINOR_SPEND_LIMIT_L2', '50000,500000'))),
    'spend_limit_level_3' => array_map('intval', explode(',', (string) env('MINOR_SPEND_LIMIT_L3', '50000,500000'))),
    'spend_limit_level_4' => array_map('intval', explode(',', (string) env('MINOR_SPEND_LIMIT_L4', '50000,500000'))),
    'spend_limit_level_5' => array_map('intval', explode(',', (string) env('MINOR_SPEND_LIMIT_L5', '100000,1000000'))),
    'spend_limit_level_6' => array_map('intval', explode(',', (string) env('MINOR_SPEND_LIMIT_L6', '200000,1500000'))),
    'spend_limit_level_7' => array_map('intval', explode(',', (string) env('MINOR_SPEND_LIMIT_L7', '200000,1500000'))),

    /*
    |--------------------------------------------------------------------------
    | Emergency Allowance
    |--------------------------------------------------------------------------
    */
    'emergency_allowance_max' => (int) env('MINOR_EMERGENCY_ALLOWANCE_MAX', 100000),

    /*
    |--------------------------------------------------------------------------
    | Blocked Merchant Categories
    |--------------------------------------------------------------------------
    */
    'blocked_merchant_categories' => array_map(
        'trim',
        array_filter(
            explode(',', (string) env('MINOR_BLOCKED_MCC', 'alcohol,tobacco,gambling,adult_content'))
        )
    ),

    /*
    |--------------------------------------------------------------------------
    | Card Limit Period (days used as monthly multiplier fallback)
    |--------------------------------------------------------------------------
    */
    'card_limit_period_days' => (int) env('MINOR_CARD_LIMIT_PERIOD_DAYS', 30),
];
