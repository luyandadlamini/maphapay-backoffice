<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Webhook endpoints are protected by signature validation instead of CSRF
        'stripe/webhook',
        'api/webhooks/*',
        // OAuth callbacks use state parameter validation
        'paysera/callback',
        'openbanking/callback',
    ];
}
