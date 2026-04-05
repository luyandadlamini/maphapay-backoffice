<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System Users
    |--------------------------------------------------------------------------
    |
    | These are special system users that own platform-level accounts like
    | treasury, suspense, liquidity pools, etc. Every account in the system
    | must belong to a user - including system accounts.
    |
    */

    'uuid' => [
        'system'   => env('SYSTEM_USER_UUID'),
        'suspense' => env('SUSPENSE_USER_UUID'),
        'treasury' => env('TREASURY_USER_UUID'),
        'pool'     => env('POOL_USER_UUID'),
    ],

    'email' => [
        'system'   => 'system@maphapay.com',
        'suspense' => 'suspense@maphapay.com',
        'treasury' => 'treasury@maphapay.com',
        'pool'     => 'pool@maphapay.com',
    ],

];
