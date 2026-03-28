<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | MTN Mobile Money (MoMo) API
    |--------------------------------------------------------------------------
    |
    | Sandbox base URL example: https://sandbox.momodeveloper.mtn.com
    | Paths such as /collection/token/ and /collection/v1_0/requesttopay are appended.
    |
    */
    'base_url' => rtrim((string) env('MTNMOMO_BASE_URL', ''), '/'),

    'subscription_key' => (string) env('MTNMOMO_SUBSCRIPTION_KEY', ''),

    'api_user' => (string) env('MTNMOMO_API_USER', ''),

    'api_key' => (string) env('MTNMOMO_API_KEY', ''),

    /*
    | X-Target-Environment header: sandbox | production
    */
    'target_environment' => (string) env('MTNMOMO_TARGET_ENVIRONMENT', 'sandbox'),

    /*
    | Public callback URL you register with MTN (informational / outbound use).
    */
    'callback_url' => (string) env('MTNMOMO_CALLBACK_URL', ''),

    'currency' => (string) env('MTNMOMO_CURRENCY', 'SZL'),

    /*
    | IPN: compare incoming X-Callback-Token to this value (constant-time).
    */
    'callback_token' => (string) env('MTNMOMO_CALLBACK_TOKEN', ''),

    /*
    | When false, callback token verification is skipped (local sandbox only).
    */
    'verify_callback_token' => (bool) env('MTNMOMO_VERIFY_CALLBACK_TOKEN', true),
];
