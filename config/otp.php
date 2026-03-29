<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Debug OTP Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, a specific debug code will be accepted for OTP verification
    | without actually sending an SMS or using Twilio. This is useful for
    | development and testing.
    |
    | WARNING: Never enable this in production!
    |
    */

    'debug_enabled' => (bool) env('OTP_DEBUG_ENABLED', false),

    'debug_code' => env('OTP_DEBUG_CODE', '123456'),
];
