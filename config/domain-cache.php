<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Domain Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for domain-specific caching strategies
    |
    */

    'ttl' => [
        'account'       => env('CACHE_TTL_ACCOUNT', 3600),        // 1 hour
        'balance'       => env('CACHE_TTL_BALANCE', 300),         // 5 minutes
        'transaction'   => env('CACHE_TTL_TRANSACTION', 1800), // 30 minutes
        'turnover'      => env('CACHE_TTL_TURNOVER', 7200),     // 2 hours
        'daily_summary' => env('CACHE_TTL_DAILY_SUMMARY', 86400), // 24 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Tags
    |--------------------------------------------------------------------------
    |
    | Enable cache tagging for better cache management (requires Redis/Memcached)
    |
    */

    'use_tags' => env('CACHE_USE_TAGS', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Warmup
    |--------------------------------------------------------------------------
    |
    | Configuration for cache warmup strategies
    |
    */

    'warmup' => [
        'enabled'    => env('CACHE_WARMUP_ENABLED', true),
        'chunk_size' => env('CACHE_WARMUP_CHUNK_SIZE', 100),
        'delay_ms'   => env('CACHE_WARMUP_DELAY_MS', 50), // Delay between chunks
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Enable cache performance monitoring
    |
    */

    'monitoring' => [
        'enabled'                => env('CACHE_MONITORING_ENABLED', true),
        'low_hit_rate_threshold' => env('CACHE_LOW_HIT_RATE_THRESHOLD', 50), // Percentage
        'log_performance'        => env('CACHE_LOG_PERFORMANCE', true),
    ],

];
