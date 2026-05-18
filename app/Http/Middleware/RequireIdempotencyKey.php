<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce that money-movement requests carry an Idempotency-Key header.
 *
 * Mount this BEFORE {@see IdempotencyMiddleware} on routes where retries
 * would otherwise cause duplicate state changes (transfers, payments).
 * IdempotencyMiddleware itself is opt-in (silently passes through when no
 * key is present) so that GET/HEAD requests work — this guard plugs the
 * gap for POST/PUT/PATCH where omitting the key is unsafe.
 *
 * The header value is validated by IdempotencyMiddleware; this middleware
 * only checks presence so the contract of "missing key → 400, bad format →
 * 400" stays cleanly attributable to the right layer.
 */
class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return response()->json([
                'status'  => 'error',
                'message' => ['Idempotency-Key header is required for this endpoint.'],
                'data'    => null,
            ], 400);
        }

        return $next($request);
    }
}
