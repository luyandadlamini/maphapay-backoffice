<?php

declare(strict_types=1);

return [
    'webhook' => [
        'enabled'        => env('KYB_WEBHOOK_ENABLED', false),
        'default_url'    => env('KYB_WEBHOOK_URL'),
        'default_secret' => env('KYB_WEBHOOK_SECRET'),
        'timeout'        => env('KYB_WEBHOOK_TIMEOUT', 30),
        'retries'        => env('KYB_WEBHOOK_RETRIES', 3),
    ],

    'verification' => [
        'auto_verify_threshold' => env('KYB_AUTO_VERIFY_THRESHOLD', 0),
    ],
];
