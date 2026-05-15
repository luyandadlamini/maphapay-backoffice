<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | eMali Eswatini Mobile API
    |--------------------------------------------------------------------------
    |
    | Plausible OAuth2-protected REST shape; real eMali API spec is not
    | publicly documented. In dev/test the base_url points at our mock
    | controllers at /__mock/wallets/emali. In production point at the
    | real eMali endpoint.
    |
    */
    'base_url' => rtrim((string) env('EMALI_BASE_URL', ''), '/'),

    'client_id'     => (string) env('EMALI_CLIENT_ID', ''),
    'client_secret' => (string) env('EMALI_CLIENT_SECRET', ''),

    'currency' => (string) env('EMALI_CURRENCY', 'SZL'),

    'callback_url'   => (string) env('EMALI_CALLBACK_URL', ''),
    'callback_token' => (string) env('EMALI_CALLBACK_TOKEN', ''),
    'hmac_key'       => (string) env('EMALI_HMAC_KEY', ''),

    'verify_callback_token' => (bool) env('EMALI_VERIFY_CALLBACK_TOKEN', true),
    'verify_hmac_signature' => (bool) env('EMALI_VERIFY_HMAC_SIGNATURE', true),
];
