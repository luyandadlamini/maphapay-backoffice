<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Nedbank Send Money API
    |--------------------------------------------------------------------------
    |
    | Plausible OAuth2 + REST shape for Nedbank Send Money. Real API is
    | bank-internal. In dev/test point base_url at
    | /__mock/wallets/nedbank-send-money.
    |
    */
    'base_url' => rtrim((string) env('NEDBANK_SEND_MONEY_BASE_URL', ''), '/'),

    'client_id'     => (string) env('NEDBANK_SEND_MONEY_CLIENT_ID', ''),
    'client_secret' => (string) env('NEDBANK_SEND_MONEY_CLIENT_SECRET', ''),

    'currency' => (string) env('NEDBANK_SEND_MONEY_CURRENCY', 'SZL'),

    'callback_url'   => (string) env('NEDBANK_SEND_MONEY_CALLBACK_URL', ''),
    'callback_token' => (string) env('NEDBANK_SEND_MONEY_CALLBACK_TOKEN', ''),
    'hmac_key'       => (string) env('NEDBANK_SEND_MONEY_HMAC_KEY', ''),

    'verify_callback_token' => (bool) env('NEDBANK_SEND_MONEY_VERIFY_CALLBACK_TOKEN', true),
    'verify_hmac_signature' => (bool) env('NEDBANK_SEND_MONEY_VERIFY_HMAC_SIGNATURE', true),
];
