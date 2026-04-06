<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Custodian
    |--------------------------------------------------------------------------
    |
    | This option controls the default custodian connector that will be used
    | when no specific custodian is specified.
    |
    */
    'default' => env('CUSTODIAN_DEFAULT', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Mock Custodian Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the mock custodian used in testing and development.
    |
    */
    'mock' => [
        'enabled'  => env('CUSTODIAN_MOCK_ENABLED', true),
        'name'     => 'Mock Bank',
        'base_url' => env('CUSTODIAN_MOCK_URL', 'https://mock-bank.local'),
        'timeout'  => 30,
        'debug'    => env('CUSTODIAN_DEBUG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custodian Providers
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the custodian providers for your
    | application. Examples of each available type of provider are
    | configured here as an example.
    |
    */
    'providers' => [

        // Example: Plaid Integration
        'plaid' => [
            'enabled'    => env('CUSTODIAN_PLAID_ENABLED', false),
            'connector'  => App\Domain\Custodian\Connectors\PlaidConnector::class,
            'name'       => 'Plaid',
            'base_url'   => env('PLAID_BASE_URL', 'https://production.plaid.com'),
            'client_id'  => env('PLAID_CLIENT_ID'),
            'secret'     => env('PLAID_SECRET'),
            'timeout'    => 30,
            'verify_ssl' => true,
        ],

        // Example: Banking as a Service Provider
        'baas_provider' => [
            'enabled'    => env('CUSTODIAN_BAAS_ENABLED', false),
            'connector'  => App\Domain\Custodian\Connectors\BaaSConnector::class,
            'name'       => 'BaaS Provider',
            'base_url'   => env('BAAS_BASE_URL'),
            'api_key'    => env('BAAS_API_KEY'),
            'timeout'    => 30,
            'verify_ssl' => true,
            'headers'    => [
                'X-Partner-Id' => env('BAAS_PARTNER_ID'),
            ],
        ],

        // Example: Crypto Custodian
        'crypto_custodian' => [
            'enabled'    => env('CUSTODIAN_CRYPTO_ENABLED', false),
            'connector'  => App\Domain\Custodian\Connectors\CryptoCustodianConnector::class,
            'name'       => 'Crypto Custodian',
            'base_url'   => env('CRYPTO_CUSTODIAN_URL'),
            'api_key'    => env('CRYPTO_CUSTODIAN_API_KEY'),
            'api_secret' => env('CRYPTO_CUSTODIAN_API_SECRET'),
            'timeout'    => 45,
            'verify_ssl' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custodian Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for caching custodian data.
    |
    */
    'cache' => [
        'enabled' => env('CUSTODIAN_CACHE_ENABLED', true),
        'ttl'     => env('CUSTODIAN_CACHE_TTL', 300), // 5 minutes
        'prefix'  => 'custodian',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custodian Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configuration for rate limiting custodian API calls.
    |
    */
    'rate_limit' => [
        'enabled'       => env('CUSTODIAN_RATE_LIMIT_ENABLED', true),
        'max_attempts'  => env('CUSTODIAN_RATE_LIMIT_MAX', 100),
        'decay_minutes' => env('CUSTODIAN_RATE_LIMIT_DECAY', 1),
    ],
];
