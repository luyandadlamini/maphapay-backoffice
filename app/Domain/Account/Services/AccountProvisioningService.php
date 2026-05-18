<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\DataObjects\Account as AccountData;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Tenancy;
use Throwable;

/**
 * Single orchestrator that idempotently produces the central directory
 * quintet for a user:
 *
 *     User -> Team (personal, owned) -> Tenant -> tenant DB -> Account -> AccountMembership (owner, active)
 *
 * Every production code path that creates a User row is required to call
 * {@see self::ensureProvisioned()} so that downstream cross-tenant features
 * (send-money recipient resolution, request-money, scheduled-send) can
 * resolve the user via the central AccountMembership directory.
 *
 * Reference implementation: this service mirrors the behaviour of
 * {@see \App\Console\Commands\RepairOwnerMembership} which has been the
 * production repair script and is the executable spec for what "complete
 * provisioning" means. The command now delegates here.
 */
final class AccountProvisioningService
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly AccountMembershipService $accountMembershipService,
        private readonly Tenancy $tenancy,
        private readonly DatabaseManager $databaseManager,
    ) {
    }

    /**
     * Idempotently provision the full quintet for $user.
     *
     * Safe to call multiple times: existing rows are reused, missing rows
     * are filled in. Returns the active owner AccountMembership.
     *
     * Restores the caller's tenancy state on exit (including the case
     * where no tenancy was initialised on entry).
     */
    public function ensureProvisioned(User $user): AccountMembership
    {
        $previousTenant = ($this->tenancy->initialized && $this->tenancy->tenant instanceof TenantContract)
            ? $this->tenancy->tenant
            : null;
        $previousDefault = config('database.default');

        try {
            $team = $this->ensureTeam($user);
            $tenant = $this->ensureTenant($team);
            $this->ensureTenantDatabase($tenant);

            $this->tenancy->initialize($tenant);

            $account = $this->ensureAccount($user);

            return $this->ensureMembership($user, $tenant, $account);
        } finally {
            $this->restoreTenancy($previousTenant, $previousDefault);
        }
    }

    private function ensureTeam(User $user): Team
    {
        $team = Team::query()
            ->where('user_id', $user->id)
            ->where('personal_team', true)
            ->first();

        if ($team !== null) {
            return $team;
        }

        $namePrefix = explode(' ', (string) $user->name, 2)[0] ?: 'Wallet';

        $team = Team::forceCreate([
            'user_id'       => $user->id,
            'name'          => $namePrefix . "'s Team",
            'personal_team' => true,
        ]);

        // Jetstream associates ownership when the team is saved via the
        // user relation; force the inverse side too so $user->ownedTeams()
        // is fresh on the same request.
        $user->ownedTeams()->save($team);

        return $team;
    }

    private function ensureTenant(Team $team): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['team_id' => $team->id],
            [
                'name' => $team->name,
                'plan' => 'default',
            ],
        );
    }

    private function ensureTenantDatabase(Tenant $tenant): void
    {
        $tenant->database()->makeCredentials();
        $tenantDatabase = $tenant->database()->getName();

        if ($tenantDatabase === null) {
            throw new RuntimeException(
                sprintf('AccountProvisioningService: unable to resolve tenant database name for tenant %s.', $tenant->id),
            );
        }

        if (! $tenant->database()->manager()->databaseExists($tenantDatabase)) {
            $this->databaseManager->ensureTenantCanBeCreated($tenant);
            $tenant->database()->manager()->createDatabase($tenant);
        }

        // Run tenant migrations defensively if the schema looks empty.
        try {
            $tableCount = DB::connection('tenant')->select(
                'SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE()',
            );
            if (($tableCount[0]->cnt ?? 0) < 5) {
                Artisan::call('migrate', [
                    '--path'  => 'database/migrations/tenant',
                    '--force' => true,
                ]);
            }
        } catch (Throwable) {
            // Fresh tenant DB may not be wired into the 'tenant' connection
            // yet on the first call; rely on the caller to run migrations
            // (Stancl's TenantCreated -> CreateDatabase pipeline ordinarily
            // handles this in production).
        }
    }

    private function ensureAccount(User $user): Account
    {
        $account = Account::query()
            ->where('user_uuid', $user->uuid)
            ->orderBy('created_at')
            ->first();

        if ($account !== null) {
            return $account;
        }

        $accountUuid = $this->accountService->createDirect(
            new AccountData(
                name: 'Maphapay Wallet',
                userUuid: $user->uuid,
            ),
        );

        $account = Account::query()->where('uuid', $accountUuid)->first();

        if ($account === null) {
            throw new RuntimeException(
                sprintf('AccountProvisioningService: failed to resolve newly created Account for user %s.', $user->uuid),
            );
        }

        return $account;
    }

    private function ensureMembership(User $user, Tenant $tenant, Account $account): AccountMembership
    {
        $existing = AccountMembership::query()
            ->where('user_uuid', $user->uuid)
            ->where('tenant_id', (string) $tenant->id)
            ->where('account_uuid', $account->uuid)
            ->where('status', 'active')
            ->where('role', 'owner')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->accountMembershipService->createOwnerMembership(
            $user,
            (string) $tenant->id,
            $account,
        );
    }

    private function restoreTenancy(?TenantContract $previousTenant, mixed $previousDefault): void
    {
        if ($this->tenancy->initialized) {
            $this->tenancy->end();
        }

        if ($previousTenant !== null) {
            $this->tenancy->initialize($previousTenant);
        }

        if (is_string($previousDefault) && $previousDefault !== '') {
            config(['database.default' => $previousDefault]);
        }
    }
}
