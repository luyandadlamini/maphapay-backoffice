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
        'attempt_window_minutes' => (int) env('MINOR_FAMILY_PUBLIC_FUNDING_WINDOW_MINUTES', 10),
        'sponsor_max_attempts_per_window' => (int) env('MINOR_FAMILY_PUBLIC_FUNDING_SPONSOR_MAX_ATTEMPTS', 5),
        'link_max_attempts_per_window' => (int) env('MINOR_FAMILY_PUBLIC_FUNDING_LINK_MAX_ATTEMPTS', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation exception queue (operator)
    |--------------------------------------------------------------------------
    */
    'reconciliation_exception' => [
        'sla_review_hours' => max(1, (int) env('MINOR_FAMILY_RECON_EXCEPTION_SLA_HOURS', 24)),
    ],
];
