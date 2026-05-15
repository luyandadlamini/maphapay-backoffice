<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Wallet provider mocks
|--------------------------------------------------------------------------
|
| Stateful HTTP mock layer for the five Eswatini wallet providers the app
| supports. Enables real money-movement testing end-to-end without external
| credentials or paid sandboxes. Mounted only when `enabled = true` AND the
| environment is not production (the boot guard in `bootstrap/app.php`
| enforces this — see also app/Domain/Ramp/Providers/MockRampProvider).
|
*/

return [
    'enabled' => (bool) env('WALLET_MOCKS_ENABLED', false),

    'allow_in_production' => (bool) env('WALLET_MOCKS_ALLOW_IN_PRODUCTION', false),

    'providers' => [
        'mtn_momo' => [
            'callback_url'               => (string) env('MTNMOMO_CALLBACK_URL', ''),
            'callback_token'             => (string) env('MTNMOMO_CALLBACK_TOKEN', ''),
            'hmac_key'                   => (string) env('MTNMOMO_HMAC_KEY', ''),
            'callback_delay_seconds'     => (int) env('MTNMOMO_MOCK_CALLBACK_DELAY', 2),
            'default_seed_balance_minor' => (int) env('MTNMOMO_MOCK_SEED_MINOR', 5_000_000),
            'currency'                   => (string) env('MTNMOMO_CURRENCY', 'SZL'),
        ],

        'emali_eswatini_mobile' => [
            'callback_url'               => (string) env('EMALI_CALLBACK_URL', ''),
            'callback_token'             => (string) env('EMALI_CALLBACK_TOKEN', ''),
            'hmac_key'                   => (string) env('EMALI_HMAC_KEY', ''),
            'callback_delay_seconds'     => (int) env('EMALI_MOCK_CALLBACK_DELAY', 2),
            'default_seed_balance_minor' => (int) env('EMALI_MOCK_SEED_MINOR', 5_000_000),
            'currency'                   => (string) env('EMALI_CURRENCY', 'SZL'),
        ],

        'fnb_ewallet' => [
            'callback_url'               => (string) env('FNB_EWALLET_CALLBACK_URL', ''),
            'callback_token'             => (string) env('FNB_EWALLET_CALLBACK_TOKEN', ''),
            'hmac_key'                   => (string) env('FNB_EWALLET_HMAC_KEY', ''),
            'callback_delay_seconds'     => (int) env('FNB_EWALLET_MOCK_CALLBACK_DELAY', 2),
            'default_seed_balance_minor' => (int) env('FNB_EWALLET_MOCK_SEED_MINOR', 5_000_000),
            'currency'                   => (string) env('FNB_EWALLET_CURRENCY', 'SZL'),
        ],

        'standard_unayo' => [
            'callback_url'               => (string) env('STANDARD_UNAYO_CALLBACK_URL', ''),
            'callback_token'             => (string) env('STANDARD_UNAYO_CALLBACK_TOKEN', ''),
            'hmac_key'                   => (string) env('STANDARD_UNAYO_HMAC_KEY', ''),
            'callback_delay_seconds'     => (int) env('STANDARD_UNAYO_MOCK_CALLBACK_DELAY', 2),
            'default_seed_balance_minor' => (int) env('STANDARD_UNAYO_MOCK_SEED_MINOR', 5_000_000),
            'currency'                   => (string) env('STANDARD_UNAYO_CURRENCY', 'SZL'),
        ],

        'nedbank_send_money' => [
            'callback_url'               => (string) env('NEDBANK_SEND_MONEY_CALLBACK_URL', ''),
            'callback_token'             => (string) env('NEDBANK_SEND_MONEY_CALLBACK_TOKEN', ''),
            'hmac_key'                   => (string) env('NEDBANK_SEND_MONEY_HMAC_KEY', ''),
            'callback_delay_seconds'     => (int) env('NEDBANK_SEND_MONEY_MOCK_CALLBACK_DELAY', 2),
            'default_seed_balance_minor' => (int) env('NEDBANK_SEND_MONEY_MOCK_SEED_MINOR', 5_000_000),
            'currency'                   => (string) env('NEDBANK_SEND_MONEY_CURRENCY', 'SZL'),
        ],
    ],
];
