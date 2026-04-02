<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
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
    public function __construct(
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {}

    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if (! $this->isEnabled($flag)) {
            $this->telemetry->logRolloutBlocked($request, $flag);
            abort(404);
        }

        return $next($request);
    }

    private function isEnabled(string $flag): bool
    {
        $value = config("maphapay_migration.{$flag}");

        if ($value !== null) {
            return (bool) $value;
        }

        return match ($flag) {
            'enable_request_money_create', 'enable_request_money_accept' => (bool) config('maphapay_migration.enable_request_money'),
            default => false,
        };
    }
}
