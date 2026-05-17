<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Pricing engine feature flag
    |--------------------------------------------------------------------------
    |
    | When true, the pricing engine resolves and records a FeeEvent on every
    | completed send-money transaction. Safe to enable incrementally because
    | FeeEventRecorder uses firstOrCreate with a deterministic idempotency key.
    |
    */

    'pricing_engine_enabled' => filter_var(env('PRICING_ENGINE_ENABLED', false), FILTER_VALIDATE_BOOL),
];
