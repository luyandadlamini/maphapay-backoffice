<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | MaphaPay compatibility API (Phase 5 / 18)
    |--------------------------------------------------------------------------
    |
    | Progressive rollout flags for routes registered in routes/api-compat.php.
    |
    */
    'enable_verification' => (bool) env('MAPHAPAY_MIGRATION_ENABLE_VERIFICATION', false),

    'enable_send_money' => (bool) env('MAPHAPAY_MIGRATION_ENABLE_SEND_MONEY', false),

    'enable_request_money' => (bool) env('MAPHAPAY_MIGRATION_ENABLE_REQUEST_MONEY', false),

    'enable_scheduled_send' => (bool) env('MAPHAPAY_MIGRATION_ENABLE_SCHEDULED_SEND', false),

    'enable_mtn_momo' => (bool) env('MAPHAPAY_MIGRATION_ENABLE_MTN_MOMO', false),

    'enable_transaction_history' => (bool) env('MAPHAPAY_MIGRATION_ENABLE_TRANSACTION_HISTORY', false),

    'enable_dashboard' => (bool) env('MAPHAPAY_MIGRATION_ENABLE_DASHBOARD', false),
];
