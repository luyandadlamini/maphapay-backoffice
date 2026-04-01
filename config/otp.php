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

    /*
    |--------------------------------------------------------------------------
    | Log mock OTP plaintext (sandbox / staging)
    |--------------------------------------------------------------------------
    |
    | When SMS_OTP_PROVIDER=mock, codes are not sent by SMS. In non-production environments
    | the plaintext code is logged automatically. Set OTP_MOCK_LOG_PLAINTEXT=true on
    | production-like hosts (e.g. Railway preview) when you need the code in logs.
    |
    */

    'mock_log_plaintext' => (bool) env('OTP_MOCK_LOG_PLAINTEXT', false),

    /*
    |--------------------------------------------------------------------------
    | Allow skip_otp_send on mobile login (registration / OTP step)
    |--------------------------------------------------------------------------
    |
    | When true, POST /api/auth/mobile/login may include skip_otp_send: true to
    | create or resolve the user without generating or delivering an OTP. Pair
    | with OTP_DEBUG_ENABLED so verify-otp accepts the debug code (e.g. 123456).
    | Intended for sandbox and local dev only — keep false in production.
    |
    */

    'allow_skip_send_on_register' => (bool) env('OTP_ALLOW_SKIP_SEND_ON_REGISTER', false),
];
