<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Exchange Rate Provider
    |--------------------------------------------------------------------------
    |
    | This option controls the default exchange rate provider that will be used
    | when no specific provider is specified.
    |
    */
    'default_provider' => env('EXCHANGE_DEFAULT_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Exchange Rate Providers
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the exchange rate providers for your
    | application. Each provider configuration should include necessary
    | credentials and settings.
    |
    */
    'providers' => [

        'mock' => [
            'enabled'   => env('EXCHANGE_MOCK_ENABLED', true),
            'name'      => 'Mock Exchange Rate Provider',
            'priority'  => 1,
            'available' => true,
            'debug'     => env('EXCHANGE_DEBUG', false),
        ],

        'fixer' => [
            'enabled'    => env('EXCHANGE_FIXER_ENABLED', false),
            'api_key'    => env('FIXER_API_KEY'),
            'base_url'   => env('FIXER_BASE_URL', 'https://api.fixer.io/v1'),
            'priority'   => 50,
            'rate_limit' => 100, // requests per minute
            'timeout'    => 30,
            'verify_ssl' => true,
            'debug'      => env('EXCHANGE_DEBUG', false),
        ],

        'exchangeratesapi' => [
            'enabled'    => env('EXCHANGE_EXCHANGERATESAPI_ENABLED', false),
            'class'      => App\Domain\Exchange\Providers\ExchangeRatesApiProvider::class,
            'api_key'    => env('EXCHANGERATESAPI_KEY'),
            'base_url'   => env('EXCHANGERATESAPI_URL', 'https://api.exchangeratesapi.io/v1'),
            'priority'   => 40,
            'rate_limit' => 250,
            'timeout'    => 30,
            'verify_ssl' => true,
        ],

        'coinbase' => [
            'enabled'    => env('EXCHANGE_COINBASE_ENABLED', false),
            'class'      => App\Domain\Exchange\Providers\CoinbaseProvider::class,
            'api_key'    => env('COINBASE_API_KEY'),
            'api_secret' => env('COINBASE_API_SECRET'),
            'base_url'   => env('COINBASE_BASE_URL', 'https://api.coinbase.com'),
            'priority'   => 60,
            'rate_limit' => 10000, // requests per hour
            'timeout'    => 30,
            'verify_ssl' => true,
        ],

        'coingecko' => [
            'enabled'    => env('EXCHANGE_COINGECKO_ENABLED', false),
            'class'      => App\Domain\Exchange\Providers\CoinGeckoProvider::class,
            'api_key'    => env('COINGECKO_API_KEY'),
            'base_url'   => env('COINGECKO_BASE_URL', 'https://api.coingecko.com/api/v3'),
            'priority'   => 70,
            'rate_limit' => 50, // requests per minute
            'timeout'    => 30,
            'verify_ssl' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Rate Refresh
    |--------------------------------------------------------------------------
    |
    | Configure automatic refreshing of exchange rates.
    |
    */
    'auto_refresh' => [
        'enabled'   => env('EXCHANGE_AUTO_REFRESH_ENABLED', false),
        'frequency' => env('EXCHANGE_AUTO_REFRESH_FREQUENCY', 'hourly'), // every_minute, every_five_minutes, etc.
        'queue'     => env('EXCHANGE_AUTO_REFRESH_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Caching
    |--------------------------------------------------------------------------
    |
    | Configure how exchange rates are cached.
    |
    */
    'cache' => [
        'enabled' => env('EXCHANGE_CACHE_ENABLED', true),
        'ttl'     => env('EXCHANGE_CACHE_TTL', 300), // seconds
        'prefix'  => 'exchange_rate',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Validation
    |--------------------------------------------------------------------------
    |
    | Configure validation thresholds for exchange rates.
    |
    */
    'validation' => [
        'max_spread_percentage'    => env('EXCHANGE_MAX_SPREAD_PERCENTAGE', 1.0),
        'max_age_seconds'          => env('EXCHANGE_MAX_AGE_SECONDS', 300),
        'max_deviation_percentage' => env('EXCHANGE_MAX_DEVIATION_PERCENTAGE', 10.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhooks for rate update notifications.
    |
    */
    'webhooks' => [
        'enabled' => env('EXCHANGE_WEBHOOKS_ENABLED', false),
        'events'  => [
            'rate_updated',
            'rate_refresh_failed',
            'provider_unavailable',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Configuration
    |--------------------------------------------------------------------------
    |
    | Configure fallback behavior when primary providers fail.
    |
    */
    'fallback' => [
        'enabled'        => env('EXCHANGE_FALLBACK_ENABLED', true),
        'use_aggregated' => env('EXCHANGE_USE_AGGREGATED', false),
        'min_providers'  => env('EXCHANGE_MIN_PROVIDERS', 2),
    ],
];
