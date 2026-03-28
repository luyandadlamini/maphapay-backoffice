<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind a maphapay_migration config flag.
 *
 * Usage:  ->middleware('migration_flag:enable_send_money')
 *
 * Returns 404 (not 503) so that disabled compat routes are indistinguishable
 * from non-existent routes to mobile clients.
 */
class RequiresMigrationFlag
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if (! config("maphapay_migration.{$flag}")) {
            abort(404);
        }

        return $next($request);
    }
}
