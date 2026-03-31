<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | SMS Service Configuration
    |--------------------------------------------------------------------------
    |
    | Transactional SMS uses sms.default_provider: "mock" (no carrier) or "twilio"
    | (Programmable SMS). OTP uses sms.otp_provider: "twilio" (Verify) or "mock".
    |
    */

    'enabled' => (bool) env('SMS_ENABLED', false),

    'default_provider' => env('SMS_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | OTP Provider
    |--------------------------------------------------------------------------
    |
    | "twilio" — Twilio Verify (lifecycle owned by Twilio).
    | "mock" — code stored locally; no SMS (see logs in non-production).
    |
    */

    'otp_provider' => env('SMS_OTP_PROVIDER', env('SMS_PROVIDER', 'mock')),

    'providers' => [
        'mock' => [
            'driver'  => 'mock',
            'enabled' => true,
        ],
        'twilio' => [
            'driver'                  => 'twilio',
            'account_sid'             => env('TWILIO_ACCOUNT_SID', ''),
            'auth_token'              => env('TWILIO_AUTH_TOKEN', ''),
            'verify_service_sid'      => env('TWILIO_VERIFY_SERVICE_SID', ''),
            'from_number'             => env('TWILIO_FROM_NUMBER', ''),
            'messaging_service_sid'   => env('TWILIO_MESSAGING_SERVICE_SID', ''),
            'enabled'                 => (bool) env('TWILIO_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Static rate card (optional)
    |--------------------------------------------------------------------------
    |
    | Used for MPP pricing display. Each row: CountryCode (ISO2), Country name,
    | Operator label, Rate (EUR per SMS as decimal string).
    | When empty, pricing falls back to sms.pricing.fallback_usdc.
    |
    */

    'rate_card' => [
        // Example: ['CountryCode' => 'LT', 'Country' => 'Lithuania', 'Operator' => '*', 'Rate' => '0.039'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    'pricing' => [
        'margin_multiplier' => (float) env('SMS_PRICING_MARGIN', 1.15),
        'eur_usd_rate'      => (float) env('SMS_EUR_USD_RATE', 1.08),
        'fallback_usdc'     => env('SMS_FALLBACK_PRICE_USDC', '50000'),
        'rate_cache_ttl'    => (int) env('SMS_RATE_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook (optional)
    |--------------------------------------------------------------------------
    |
    | HMAC-SHA256 secret for custom delivery-report callbacks you wire to
    | SmsService::handleDeliveryReport().
    |
    */

    'webhook' => [
        'secret' => env('SMS_WEBHOOK_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'sender_id'       => env('SMS_SENDER_ID', 'Zelta'),
        'max_message_len' => 1600,
        'test_mode'       => (bool) env('SMS_TEST_MODE', false),
    ],
];
