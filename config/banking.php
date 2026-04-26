<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Banking Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for local bank integration and virtual account numbers.
    |
    */

    'account_prefix' => env('BANK_ACCOUNT_PREFIX', '8'),

    'bank_code' => env('BANK_CODE', 'MAPHAPAY'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for user accounts and display. SZL (Swazi Lilangeni)
    | is used for the local market with symbol E.
    |
    | `currency_symbol` is prepended to Filament/admin stats that format minor
    | units (cents). Set BANKING_CURRENCY_SYMBOL=$ for USD, R for ZAR, etc.
    | There is no separate Filament "theme" setting — use these env keys.
    |
    */

    'default_currency' => env('BANKING_DEFAULT_CURRENCY', 'SZL'),

    'currency_symbol' => env('BANKING_CURRENCY_SYMBOL', 'E'),

    /*
    |--------------------------------------------------------------------------
    | Linked External Bank Accounts
    |--------------------------------------------------------------------------
    |
    | Settings for users' linked external bank accounts (for withdrawals
    | and external transfers).
    |
    */

    'linked_bank_accounts' => [
        'enabled'              => true,
        'require_verification' => env('BANK_REQUIRE_VERIFICATION', true),
    ],

];
