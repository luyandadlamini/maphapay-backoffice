<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | FNB eWallet API (FirstRand Open Banking-style)
    |--------------------------------------------------------------------------
    |
    | Plausible OAuth2 + REST shape; the real FNB eWallet API spec is
    | bank-internal and not publicly documented. In dev/test point
    | base_url at /__mock/wallets/fnb-ewallet.
    |
    */
    'base_url' => rtrim((string) env('FNB_EWALLET_BASE_URL', ''), '/'),

    'client_id'     => (string) env('FNB_EWALLET_CLIENT_ID', ''),
    'client_secret' => (string) env('FNB_EWALLET_CLIENT_SECRET', ''),

    'currency' => (string) env('FNB_EWALLET_CURRENCY', 'SZL'),

    'callback_url'   => (string) env('FNB_EWALLET_CALLBACK_URL', ''),
    'callback_token' => (string) env('FNB_EWALLET_CALLBACK_TOKEN', ''),
    'hmac_key'       => (string) env('FNB_EWALLET_HMAC_KEY', ''),

    'verify_callback_token' => (bool) env('FNB_EWALLET_VERIFY_CALLBACK_TOKEN', true),
    'verify_hmac_signature' => (bool) env('FNB_EWALLET_VERIFY_HMAC_SIGNATURE', true),
];
