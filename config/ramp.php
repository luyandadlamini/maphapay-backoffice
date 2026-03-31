<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Ramp Provider
    |--------------------------------------------------------------------------
    */

    'default_provider' => env('RAMP_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Allow Mock Provider in Production
    |--------------------------------------------------------------------------
    |
    | Set RAMP_ALLOW_MOCK=false in .env to disallow mock provider in production.
    | Default is true for development/testing convenience.
    |
    */

    'allow_mock' => (bool) env('RAMP_ALLOW_MOCK', true),

    /*
    |--------------------------------------------------------------------------
    | Provider Configuration
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'mock' => [
            'driver'  => 'mock',
            'enabled' => true,
        ],

        'onramper' => [
            'driver'               => 'onramper',
            'api_key'              => env('ONRAMPER_API_KEY'),
            'secret_key'           => env('ONRAMPER_SECRET_KEY'),
            'base_url'             => env('ONRAMPER_BASE_URL', 'https://api.onramper.com'),
            'success_redirect_url' => env('ONRAMPER_SUCCESS_REDIRECT_URL'),
            'enabled'              => (bool) env('ONRAMPER_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    */

    'supported_fiat'   => ['USD', 'EUR', 'GBP'],
    'supported_crypto' => ['USDC', 'USDT', 'ETH', 'BTC'],

    /*
    |--------------------------------------------------------------------------
    | Transaction Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'min_fiat_amount' => (float) env('RAMP_MIN_AMOUNT', 10.00),
        'max_fiat_amount' => (float) env('RAMP_MAX_AMOUNT', 10000.00),
        'daily_limit'     => (float) env('RAMP_DAILY_LIMIT', 50000.00),
    ],

];
