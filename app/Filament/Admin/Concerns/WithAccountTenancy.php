<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

trait WithAccountTenancy
{
    public function initializeTenancyForRecord(Model $record): void
    {
        $accountUuid = (string) ($record->getAttribute('uuid') ?? $record->getAttribute('account_uuid'));

        if ($accountUuid === '') {
            throw new RuntimeException(sprintf(
                'WithAccountTenancy: record of type %s has no uuid/account_uuid attribute',
                $record::class,
            ));
        }

        $membership = AccountMembership::query()
            ->where('account_uuid', $accountUuid)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            throw new RuntimeException(sprintf(
                'WithAccountTenancy: no active membership for account %s; cannot initialize tenancy',
                $accountUuid,
            ));
        }

        $tenant = Tenant::find($membership->tenant_id);

        if ($tenant === null) {
            throw new RuntimeException(sprintf(
                'WithAccountTenancy: membership references missing tenant %s',
                $membership->tenant_id,
            ));
        }

        $tenancy = app(Tenancy::class);

        $currentTenant = $tenancy->tenant;
        if ($tenancy->initialized && $currentTenant instanceof TenantContract && $currentTenant->getTenantKey() === $tenant->getTenantKey()) {
            return;
        }

        if ($tenancy->initialized) {
            $tenancy->end();
        }

        // Snapshot the default connection before initializing tenancy.
        // Stancl's DatabaseTenancyBootstrapper changes database.default to 'tenant'
        // on initialization and to 'central' on revert. In the Filament admin panel
        // (no tenancy middleware) neither of those is the correct application default
        // after the page lifecycle ends. We restore it ourselves via releaseAccountTenancy.
        $this->accountTenancyPreviousDefault = config('database.default');

        $tenancy->initialize($tenant);

        // After initialization the default connection is 'tenant'. Stancl's bootstrapper
        // has already configured that connection for us. Restore the application default so
        // that Filament / Laravel internals (queues, cache, etc.) continue to use the central
        // or mysql connection rather than the tenant connection for their own bookkeeping.
        $this->restoreDefaultConnection();
    }

    public function releaseAccountTenancy(): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->initialized) {
            $tenancy->end();
        }

        // After tenancy->end(), Stancl sets the default to 'central'. Restore the
        // original application default (typically 'mysql') so subsequent code and
        // the next test's setUp work on the expected connection.
        $this->restoreDefaultConnection();
    }

    private function restoreDefaultConnection(): void
    {
        $original = $this->accountTenancyPreviousDefault ?? config('database.default');
        $centralConnection = config('tenancy.database.central_connection', 'central');

        // Only restore if Stancl changed the default away from what we started with.
        $current = config('database.default');
        if ($current === 'tenant' || $current === $centralConnection) {
            $target = ($original !== 'tenant' && $original !== $centralConnection)
                ? $original
                : 'mysql';

            app('db')->setDefaultConnection($target);
            config(['database.default' => $target]);
        }

        $this->accountTenancyPreviousDefault = null;
    }

    /** @var string|null */
    private ?string $accountTenancyPreviousDefault = null;
}
