<?php

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
        'enabled' => true,
        'require_verification' => env('BANK_REQUIRE_VERIFICATION', true),
    ],

];
