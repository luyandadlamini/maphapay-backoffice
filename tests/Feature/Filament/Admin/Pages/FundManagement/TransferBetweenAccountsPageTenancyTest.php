<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Pages\FundManagement;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Pages\FundManagement\TransferBetweenAccountsPage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;
use Throwable;

/**
 * TransferBetweenAccountsPage — cross-tenant tenancy tests.
 *
 * This page deals with TWO accounts that may belong to DIFFERENT tenants.
 * These tests verify that:
 *
 *  1. Selecting the source account initialises tenancy for its tenant.
 *  2. Selecting the destination account switches to its tenant, even when it
 *     differs from the source tenant (WithAccountTenancy handles the end → initialize).
 *  3. Tenancy is NOT initialised on mount before any account is chosen.
 *
 * Note: UsesTenantConnection short-circuits actual cross-DB I/O in the test
 * environment, so we assert Stancl singleton state (initialized flag + active
 * tenant key) rather than real DB writes.
 */
class TransferBetweenAccountsPageTenancyTest extends TestCase
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

    /** @var list<string> Tenant IDs seeded in this test; deleted in tearDown. */
    private array $seededTenantIds = [];

    /** @var list<string> Account UUIDs seeded in this test; deleted in tearDown. */
    private array $seededAccountUuids = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isInMemorySqlite()) {
            $this->markTestSkipped(
                'TransferBetweenAccountsPage tenancy tests require MySQL (SQLite :memory: cannot share tables across connections)'
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

        if ($this->seededAccountUuids !== [] || $this->seededTenantIds !== []) {
            try {
                DB::connection('central')->reconnect();
            } catch (Throwable) {
                // ignore reconnect failure; cleanup is best-effort
            }

            try {
                DB::reconnect();
            } catch (Throwable) {
                // ignore
            }
        }

        if ($this->seededAccountUuids !== []) {
            try {
                DB::connection('central')
                    ->table('account_memberships')
                    ->whereIn('account_uuid', $this->seededAccountUuids)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }

            try {
                DB::table('accounts')
                    ->whereIn('uuid', $this->seededAccountUuids)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }

        if ($this->seededTenantIds !== []) {
            try {
                DB::connection('central')
                    ->table('tenants')
                    ->whereIn('id', $this->seededTenantIds)
                    ->delete();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }

        $this->seededTenantIds = [];
        $this->seededAccountUuids = [];

        parent::tearDown();
    }

    /**
     * Insert a tenant row directly into the central DB without firing Stancl events.
     *
     * Bypasses Tenant::factory()->create() to avoid the CreateDatabase job which can
     * fail with "1615 Prepared statement needs to be re-prepared" on MySQL after
     * migration-heavy setUp() runs.
     */
    private function seedTenantDirectly(string $tenantId, string $name = 'TransferBetweenAccountsPage test tenant'): Tenant
    {
        $central = DB::connection('central');

        if (! $central->table('tenants')->where('id', $tenantId)->exists()) {
            $central->table('tenants')->insert([
                'id'            => $tenantId,
                'name'          => $name,
                'plan'          => 'default',
                'team_id'       => null,
                'trial_ends_at' => null,
                'created_at'    => now(),
                'updated_at'    => now(),
                'data'          => json_encode([]),
            ]);
        }

        $this->seededTenantIds[] = $tenantId;

        /** @var Tenant */
        return Tenant::find($tenantId);
    }

    /**
     * Helper: seed an Account + AccountMembership pair directly (no model events).
     */
    private function seedAccountInTenant(Tenant $tenant): Account
    {
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        $this->seededAccountUuids[] = $accountUuid;

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        Account::create([
            'uuid'      => $accountUuid,
            'user_uuid' => $userUuid,
            'name'      => 'Test Wallet ' . substr($accountUuid, 0, 8),
        ]);

        /** @var Account */
        return Account::where('uuid', $accountUuid)->first();
    }

    // -------------------------------------------------------------------------

    #[Test]
    public function tenancy_is_not_initialized_on_mount_before_accounts_are_selected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        Livewire::test(TransferBetweenAccountsPage::class)
            ->assertSuccessful();

        $this->assertFalse(app(Tenancy::class)->initialized);
    }

    #[Test]
    public function looking_up_source_account_initializes_tenancy_for_source_tenant(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $sourceTenant = $this->seedTenantDirectly((string) Str::uuid(), 'Source tenant');
        $sourceAccount = $this->seedAccountInTenant($sourceTenant);

        $component = Livewire::test(TransferBetweenAccountsPage::class);
        $component->assertSuccessful();
        $component->call('lookupSourceAccount', $sourceAccount->uuid);

        $tenancy = app(Tenancy::class);
        $this->assertTrue($tenancy->initialized, 'Tenancy should be initialized after source account lookup');

        $activeTenant = $tenancy->tenant;
        $this->assertInstanceOf(Tenant::class, $activeTenant);
        $this->assertSame($sourceTenant->id, $activeTenant->getTenantKey());
    }

    #[Test]
    public function looking_up_destination_account_switches_to_destination_tenant(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $sourceTenant = $this->seedTenantDirectly((string) Str::uuid(), 'Source tenant');
        $destinationTenant = $this->seedTenantDirectly((string) Str::uuid(), 'Destination tenant');
        $sourceAccount = $this->seedAccountInTenant($sourceTenant);
        $destinationAccount = $this->seedAccountInTenant($destinationTenant);

        // First look up the source account (initializes source tenant).
        // Then look up the destination account (must switch to destination tenant).
        $component = Livewire::test(TransferBetweenAccountsPage::class);
        $component->assertSuccessful();
        $component->call('lookupSourceAccount', $sourceAccount->uuid);
        $component->call('lookupDestinationAccount', $destinationAccount->uuid);

        $tenancy = app(Tenancy::class);
        $this->assertTrue($tenancy->initialized, 'Tenancy should remain initialized after destination lookup');

        $activeTenant = $tenancy->tenant;
        $this->assertInstanceOf(Tenant::class, $activeTenant);
        $this->assertSame(
            $destinationTenant->id,
            $activeTenant->getTenantKey(),
            'Active tenant should be the destination tenant after its account is looked up'
        );
    }

    #[Test]
    public function looking_up_destination_in_same_tenant_as_source_keeps_tenant_initialized(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        // Both accounts share the same tenant — no tenant switch expected.
        $sharedTenant = $this->seedTenantDirectly((string) Str::uuid(), 'Shared tenant');
        $sourceAccount = $this->seedAccountInTenant($sharedTenant);
        $destinationAccount = $this->seedAccountInTenant($sharedTenant);

        $component = Livewire::test(TransferBetweenAccountsPage::class);
        $component->assertSuccessful();
        $component->call('lookupSourceAccount', $sourceAccount->uuid);
        $component->call('lookupDestinationAccount', $destinationAccount->uuid);

        $tenancy = app(Tenancy::class);
        $this->assertTrue($tenancy->initialized);

        $activeTenant = $tenancy->tenant;
        $this->assertInstanceOf(Tenant::class, $activeTenant);
        $this->assertSame($sharedTenant->id, $activeTenant->getTenantKey());
    }

    #[Test]
    public function clearing_source_account_uuid_unsets_source_account_model(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $this->actingAs($admin);

        $tenant = $this->seedTenantDirectly((string) Str::uuid());
        $sourceAccount = $this->seedAccountInTenant($tenant);

        $component = Livewire::test(TransferBetweenAccountsPage::class);
        $component->call('lookupSourceAccount', $sourceAccount->uuid);
        $component->call('lookupSourceAccount', ''); // clear

        $component->assertSet('sourceAccount', null);
    }
}
