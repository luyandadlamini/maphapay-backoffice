<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Standard Bank Unayo API
    |--------------------------------------------------------------------------
    |
    | Plausible OAuth2 + REST shape for Standard Bank's Unayo mobile-money
    | product. Real API is bank-internal. In dev/test point base_url at
    | /__mock/wallets/standard-unayo.
    |
    */
    'base_url' => rtrim((string) env('STANDARD_UNAYO_BASE_URL', ''), '/'),

    'client_id'     => (string) env('STANDARD_UNAYO_CLIENT_ID', ''),
    'client_secret' => (string) env('STANDARD_UNAYO_CLIENT_SECRET', ''),

    'currency' => (string) env('STANDARD_UNAYO_CURRENCY', 'SZL'),

    'callback_url'   => (string) env('STANDARD_UNAYO_CALLBACK_URL', ''),
    'callback_token' => (string) env('STANDARD_UNAYO_CALLBACK_TOKEN', ''),
    'hmac_key'       => (string) env('STANDARD_UNAYO_HMAC_KEY', ''),

    'verify_callback_token' => (bool) env('STANDARD_UNAYO_VERIFY_CALLBACK_TOKEN', true),
    'verify_hmac_signature' => (bool) env('STANDARD_UNAYO_VERIFY_HMAC_SIGNATURE', true),
];
