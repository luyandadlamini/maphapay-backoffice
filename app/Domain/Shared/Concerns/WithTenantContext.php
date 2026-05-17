<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Tenant;
use Closure;
use RuntimeException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

trait WithTenantContext
{
    /**
     * Run a callback inside the correct tenant context for the given account UUID.
     *
     * Stancl's DatabaseTenancyBootstrapper changes database.default to 'tenant' on
     * initialize() and to 'central' on end(). Non-HTTP code paths (jobs, commands,
     * workflow activities) have no tenancy middleware, so we snapshot and restore the
     * application default ourselves to avoid leaking the 'central' connection into
     * subsequent Laravel internals (queues, cache, etc.).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withAccountTenancy(string $accountUuid, Closure $callback): mixed
    {
        $membership = AccountMembership::query()
            ->where('account_uuid', $accountUuid)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            throw new RuntimeException(sprintf(
                'WithTenantContext: no active membership for account %s',
                $accountUuid,
            ));
        }

        $tenant = Tenant::find($membership->tenant_id);

        if ($tenant === null) {
            throw new RuntimeException(sprintf(
                'WithTenantContext: missing tenant %s for account %s',
                $membership->tenant_id,
                $accountUuid,
            ));
        }

        $tenancy = app(Tenancy::class);
        $wasInitialized = $tenancy->initialized;
        $currentTenant = $tenancy->tenant;
        $previousTenant = ($wasInitialized && $currentTenant instanceof TenantContract) ? $currentTenant : null;

        // Idempotency guard: if tenancy is already initialised for the same tenant,
        // run the callback directly without tearing down and re-initialising.  This
        // matches the behaviour of WithAccountTenancy and avoids unnecessary end/
        // initialize cycles in nested calls (e.g. a command that calls withAccountTenancy
        // for the same account multiple times in sequence).
        // Use $currentTenant (already narrowed to TenantContract above) rather than
        // accessing $tenancy->tenant again, which PHPStan sees as Model|TenantContract.
        $alreadyInTenant = $wasInitialized
            && $currentTenant instanceof TenantContract
            && $currentTenant->getTenantKey() === $tenant->getTenantKey();

        if ($alreadyInTenant) {
            return $callback();
        }

        // Snapshot the application default connection before Stancl changes it.
        $previousDefault = config('database.default');

        if ($wasInitialized) {
            $tenancy->end();
        }

        $tenancy->initialize($tenant);

        // Restore the application default so that Laravel internals (queue, cache, etc.)
        // continue to use their own connection rather than the freshly-set 'tenant' one.
        $this->restoreTenantContextDefault($previousDefault);

        try {
            return $callback();
        } finally {
            $tenancy->end();
            // After end(), Stancl sets the default to 'central'. Restore what we started with.
            $this->restoreTenantContextDefault($previousDefault);

            if ($wasInitialized && $previousTenant !== null) {
                $tenancy->initialize($previousTenant);
                $this->restoreTenantContextDefault($previousDefault);
            }
        }
    }

    private function restoreTenantContextDefault(string $previousDefault): void
    {
        $centralConnection = config('tenancy.database.central_connection', 'central');
        $current = config('database.default');

        if ($current === 'tenant' || $current === $centralConnection) {
            $target = ($previousDefault !== 'tenant' && $previousDefault !== $centralConnection)
                ? $previousDefault
                : 'mysql';

            app('db')->setDefaultConnection($target);
            config(['database.default' => $target]);
        }
    }
}
