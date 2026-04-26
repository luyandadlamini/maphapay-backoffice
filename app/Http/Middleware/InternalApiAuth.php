<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $providedKey = $request->header('X-Internal-Api-Key');
        $expectedKey = config('app.internal_api_key');

        if (empty($providedKey) || $providedKey !== $expectedKey) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid or missing API key'],
            ], 401);
        }

        return $next($request);
    }
}
