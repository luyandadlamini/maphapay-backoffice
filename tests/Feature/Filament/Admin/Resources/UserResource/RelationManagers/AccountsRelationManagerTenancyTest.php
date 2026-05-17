<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources\UserResource\RelationManagers;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Resources\UserResource\RelationManagers\AccountsRelationManager;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;
use Throwable;

class AccountsRelationManagerTenancyTest extends TestCase
{
    /**
     * Disable DB transaction wrapping — we bypass model events to avoid the
     * Stancl CreateDatabase job's "1615 Prepared statement needs to be re-prepared"
     * failure on MySQL after migration-heavy setUp() runs.
     *
     * @return array<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    /** @var list<string> Tenant IDs staged for deletion in tearDown. */
    private array $stagedTenantIds = [];

    /** @var list<string> Account UUIDs staged for deletion in tearDown. */
    private array $stagedAccountUuids = [];

    /** @var list<string> User UUIDs staged for deletion in tearDown. */
    private array $stagedUserUuids = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isInMemorySqlite()) {
            $this->markTestSkipped(
                'AccountsRelationManager tenancy tests require MySQL (SQLite :memory: cannot share tables across connections)'
            );
        }

        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    }

    protected function tearDown(): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->initialized) {
            $tenancy->end();
        }

        // Reconnect before cleanup to avoid stale connection state after tenancy operations.
        try {
            DB::connection('central')->reconnect();
        } catch (Throwable) {
            // ignore
        }

        try {
            DB::reconnect();
        } catch (Throwable) {
            // ignore
        }

        $this->cleanupStagedData();

        $this->stagedTenantIds    = [];
        $this->stagedAccountUuids = [];
        $this->stagedUserUuids    = [];

        parent::tearDown();
    }

    private function cleanupStagedData(): void
    {
        if ($this->stagedAccountUuids !== []) {
            try {
                DB::connection('central')
                    ->table('account_memberships')
                    ->whereIn('account_uuid', $this->stagedAccountUuids)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }

            try {
                DB::table('accounts')
                    ->whereIn('uuid', $this->stagedAccountUuids)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }

        if ($this->stagedTenantIds !== []) {
            try {
                DB::connection('central')
                    ->table('tenants')
                    ->whereIn('id', $this->stagedTenantIds)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }

        if ($this->stagedUserUuids !== []) {
            try {
                DB::table('users')
                    ->whereIn('uuid', $this->stagedUserUuids)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }
    }

    /**
     * Stage a user UUID for deletion and pre-clean any stale row from a prior run.
     * Register BEFORE creating so tearDown cleans up even if creation fails.
     */
    private function stageUserUuid(string $uuid): void
    {
        $this->stagedUserUuids[] = $uuid;

        // Pre-clean stale row from a prior interrupted run to prevent unique-key collision.
        try {
            DB::table('users')->where('uuid', $uuid)->delete();
        } catch (Throwable) {
            // best-effort
        }
    }

    /**
     * Stage an account UUID for deletion and pre-clean stale rows.
     */
    private function stageAccountUuid(string $uuid): void
    {
        $this->stagedAccountUuids[] = $uuid;

        try {
            DB::connection('central')
                ->table('account_memberships')
                ->where('account_uuid', $uuid)
                ->delete();
        } catch (Throwable) {
            // best-effort
        }

        try {
            DB::table('accounts')->where('uuid', $uuid)->delete();
        } catch (Throwable) {
            // best-effort
        }
    }

    /**
     * Insert a tenant row directly into the central DB without firing Stancl events.
     *
     * Bypasses Tenant::factory()->create() to avoid the CreateDatabase job which can
     * fail with "1615 Prepared statement needs to be re-prepared" on MySQL after
     * migration-heavy setUp() runs.
     */
    private function seedTenantDirectly(string $tenantId): Tenant
    {
        $this->stagedTenantIds[] = $tenantId;

        $central = DB::connection('central');

        if (! $central->table('tenants')->where('id', $tenantId)->exists()) {
            $central->table('tenants')->insert([
                'id'            => $tenantId,
                'name'          => 'AccountsRelationManager test tenant',
                'plan'          => 'default',
                'team_id'       => null,
                'trial_ends_at' => null,
                'created_at'    => now(),
                'updated_at'    => now(),
                'data'          => json_encode([]),
            ]);
        }

        /** @var Tenant */
        return Tenant::find($tenantId);
    }

    /**
     * Build the standard fixture: one admin, one owner user, one account with an
     * active membership for the given (or freshly generated) tenant.
     *
     * @return array{admin: User, owner: User, account: Account, tenant: Tenant}
     */
    private function buildFixture(?string $tenantId = null): array
    {
        $tenantId    = $tenantId ?? (string) Str::uuid();
        $adminUuid   = (string) Str::uuid();
        $ownerUuid   = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        // Register UUIDs BEFORE creating so tearDown can clean up even on failure.
        $this->stageUserUuid($adminUuid);
        $this->stageUserUuid($ownerUuid);
        $this->stageAccountUuid($accountUuid);

        $tenant = $this->seedTenantDirectly($tenantId);

        $admin = User::factory()->create(['uuid' => $adminUuid]);
        $admin->assignRole('finance-lead');
        $this->actingAs($admin);

        AccountMembership::factory()->create([
            'user_uuid'    => $ownerUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        Account::create([
            'uuid'      => $accountUuid,
            'user_uuid' => $ownerUuid,
            'name'      => 'Test Wallet',
        ]);

        $owner = User::factory()->create(['uuid' => $ownerUuid]);

        return compact('admin', 'owner', 'tenant');
    }

    #[Test]
    public function balance_column_resolves_without_throwing_for_an_account_with_active_membership(): void
    {
        // The balance column's getStateUsing closure wraps reads in withAccountTenancy().
        // An unhandled RuntimeException (no active membership / missing tenant) would
        // surface as a Livewire 500 and the assertSuccessful() below would fail.
        ['owner' => $owner] = $this->buildFixture();

        Livewire::test(AccountsRelationManager::class, [
            'ownerRecord' => $owner,
            'pageClass'   => \App\Filament\Admin\Resources\UserResource\Pages\EditUser::class,
        ])->assertSuccessful();
    }

    #[Test]
    public function tenancy_is_released_after_per_row_balance_read(): void
    {
        // After the Livewire render the tenancy singleton must be back to a
        // predictable (un-initialized) state so subsequent requests are not
        // accidentally served from the wrong tenant connection.
        ['owner' => $owner] = $this->buildFixture();

        Livewire::test(AccountsRelationManager::class, [
            'ownerRecord' => $owner,
            'pageClass'   => \App\Filament\Admin\Resources\UserResource\Pages\EditUser::class,
        ])->assertSuccessful();

        $tenancy = app(Tenancy::class);
        $this->assertFalse(
            $tenancy->initialized,
            'Tenancy must be released after per-row balance read to avoid connection leaks'
        );
    }
}
