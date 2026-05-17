<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Concerns;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Concerns\WithAccountTenancy;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;
use Throwable;

/**
 * Integration test for WithAccountTenancy::initializeTenancyForRecord().
 *
 * KNOWN LIMITATION — testing environment short-circuit
 * =====================================================
 * UsesTenantConnection::shouldUseDefaultConnection() returns true whenever
 * config('app.env') === 'testing'.  This means Account and AccountBalance
 * models always resolve getConnectionName() to null (the default connection)
 * during Pest/PHPUnit runs, regardless of whether Stancl tenancy has been
 * initialized.  As a result we cannot assert that balance rows seeded inside
 * a tenant context are visible to Account::where() — they would be written to
 * and read from the same default connection whether tenancy is active or not.
 *
 * What IS testable here:
 *   - initializeTenancyForRecord() correctly resolves the membership, finds the
 *     tenant, and calls Tenancy::initialize() — observable via Tenancy::$initialized
 *     and Tenancy::$tenant on the singleton.
 *   - releaseAccountTenancy() tears down the initialized state cleanly.
 *   - The actual DB-connection swap behaviour is covered by a runtime smoke test
 *     (Task 7.4) that exercises the concern against production data in a
 *     non-testing environment where UsesTenantConnection routes to the 'tenant'
 *     connection.
 *
 * KNOWN LIMITATION — Stancl CreateDatabase event
 * ================================================
 * Tenant::factory()->create() fires Stancl's TenantCreated event which dispatches a
 * CreateDatabase job.  On MySQL this can fail with "1615 Prepared statement needs to
 * be re-prepared" after migration-heavy setUp() runs.  To avoid this we insert the
 * tenant row directly into the 'central' connection (bypassing model events) and
 * load the Tenant via find().  The tenancy_db_name in the data JSON column is left
 * empty so DatabaseTenancyBootstrapper merely reconfigures the 'tenant' connection
 * to the same DB as the central connection, which is the correct single-DB test setup.
 */
class WithAccountTenancyIntegrationTest extends TestCase
{
    /**
     * Disable DB transaction wrapping so that central-connection writes (account_memberships)
     * and the direct DB::connection('central')->table('tenants')->insert() calls are not
     * subject to cross-connection lock contention during rollback.  Each test cleans up
     * its own rows in tearDown.
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

    /** @var list<string> Tenant IDs seeded in the current test; deleted in tearDown. */
    private array $seededTenantIds = [];

    /** @var list<string> Account UUIDs seeded in the current test; deleted in tearDown. */
    private array $seededAccountUuids = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isInMemorySqlite()) {
            $this->markTestSkipped(
                'WithAccountTenancy integration tests require MySQL (SQLite :memory: cannot share tables across connections)'
            );
        }
    }

    protected function tearDown(): void
    {
        // End tenancy if still active so subsequent tests start clean.
        $tenancy = app(Tenancy::class);

        if ($tenancy->initialized) {
            $tenancy->end();
        }

        // Clean up rows we wrote without transaction wrapping.
        // MySQL can throw 1615 ("Prepared statement needs to be re-prepared") after
        // Tenancy::initialize()/end() re-configures connections via DDL.  Reconnecting
        // the PDO before cleanup avoids that by starting a fresh connection without
        // stale prepared statement metadata.
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
                // best-effort cleanup; stale rows in the test DB are acceptable
            }

            try {
                DB::table('account_balances')
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
     * migration-heavy setUp() runs.  Returns the loaded Tenant model.
     *
     * The inserted tenant ID is tracked in $seededTenantIds for tearDown cleanup.
     */
    private function seedTenantDirectly(string $tenantId): Tenant
    {
        $central = DB::connection('central');

        if (! $central->table('tenants')->where('id', $tenantId)->exists()) {
            $central->table('tenants')->insert([
                'id'            => $tenantId,
                'name'          => 'Integration test tenant',
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

    #[Test]
    public function tenancy_is_initialized_on_the_singleton_after_initialize_for_record(): void
    {
        $tenantId = (string) Str::uuid();
        $tenant = $this->seedTenantDirectly($tenantId);
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();
        $this->seededAccountUuids[] = $accountUuid;

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $accountStub = (new Account())->forceFill([
            'uuid'      => $accountUuid,
            'user_uuid' => $userUuid,
        ]);

        $host->initializeTenancyForRecord($accountStub);

        $tenancy = app(Tenancy::class);

        $this->assertTrue($tenancy->initialized, 'Tenancy singleton should be initialized after initializeTenancyForRecord');
        $this->assertInstanceOf(Tenant::class, $tenancy->tenant);
        $this->assertSame(
            $tenant->getTenantKey(),
            $tenancy->tenant->getTenantKey(),
            'Tenancy singleton should hold the correct tenant after initialization',
        );

        $host->releaseAccountTenancy();
    }

    #[Test]
    public function default_connection_is_restored_after_initialize_for_record(): void
    {
        // After initializeTenancyForRecord() Stancl's DatabaseTenancyBootstrapper sets
        // database.default to 'tenant'.  WithAccountTenancy immediately restores the
        // original default so that Filament / Laravel internals (queues, cache, etc.)
        // continue to use the central connection.  This test asserts that restore happens.

        $tenantId = (string) Str::uuid();
        $tenant = $this->seedTenantDirectly($tenantId);
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();
        $this->seededAccountUuids[] = $accountUuid;

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $originalDefault = config('database.default');

        $host = new class () {
            use WithAccountTenancy;
        };

        $accountStub = (new Account())->forceFill([
            'uuid'      => $accountUuid,
            'user_uuid' => $userUuid,
        ]);

        $host->initializeTenancyForRecord($accountStub);

        // After initializeTenancyForRecord the trait must have restored the default.
        $this->assertNotSame(
            'tenant',
            config('database.default'),
            'initializeTenancyForRecord must restore the default connection after Stancl initialization',
        );
        $this->assertNotSame(
            'central',
            config('database.default'),
            'initializeTenancyForRecord must not leave the default as "central"',
        );

        $host->releaseAccountTenancy();

        // After release the default should match what it was before.
        $this->assertSame(
            $originalDefault,
            config('database.default'),
            'releaseAccountTenancy must restore the original database.default',
        );
    }

    #[Test]
    public function tenancy_ends_cleanly_after_release(): void
    {
        $tenantId = (string) Str::uuid();
        $tenant = $this->seedTenantDirectly($tenantId);
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();
        $this->seededAccountUuids[] = $accountUuid;

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $accountStub = (new Account())->forceFill([
            'uuid'      => $accountUuid,
            'user_uuid' => $userUuid,
        ]);

        $host->initializeTenancyForRecord($accountStub);

        $this->assertTrue(app(Tenancy::class)->initialized);

        $host->releaseAccountTenancy();

        $this->assertFalse(app(Tenancy::class)->initialized, 'Tenancy singleton should no longer be initialized after releaseAccountTenancy');
    }

    #[Test]
    public function db_context_swap_limitation_is_documented(): void
    {
        // This test documents — via observable assertions — that the UsesTenantConnection
        // short-circuit prevents verifying DB-level isolation through Eloquent in tests.
        //
        // UsesTenantConnection::shouldUseDefaultConnection() returns true in the
        // 'testing' environment (see app/Domain/Shared/Traits/UsesTenantConnection.php:75).
        // Account and AccountBalance therefore always use the default connection regardless
        // of whether Stancl tenancy is active.
        //
        // Verification of the actual cross-DB isolation is deferred to Task 7.4 smoke test
        // which runs against prod/staging data with app.env != 'testing'.

        $tenantId = (string) Str::uuid();
        $tenant = $this->seedTenantDirectly($tenantId);
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();
        $this->seededAccountUuids[] = $accountUuid;

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $accountStub = (new Account())->forceFill([
            'uuid'      => $accountUuid,
            'user_uuid' => $userUuid,
        ]);

        $host->initializeTenancyForRecord($accountStub);

        // The purpose of this test is to document the UsesTenantConnection short-circuit:
        // even with tenancy initialized the Eloquent Account/AccountBalance models write to
        // the default connection, not the 'tenant' connection.  We assert the Stancl
        // Tenancy singleton is initialized and holds the correct tenant, which IS observable
        // even under the short-circuit.  We deliberately do not attempt to write
        // AccountBalance rows here to avoid FK constraint complexity (asset_code references
        // the assets table which may not have an SZL row in the test schema).
        //
        // The cross-DB data seeding and isolation assertion are reserved for Task 7.4 smoke
        // test which runs in an environment where app.env != 'testing'.

        $tenancy = app(Tenancy::class);

        $this->assertTrue(
            $tenancy->initialized,
            'Tenancy singleton must be initialized even though UsesTenantConnection short-circuits to default connection in testing',
        );

        $activeTenant = $tenancy->tenant;
        $this->assertInstanceOf(Tenant::class, $activeTenant);
        $this->assertSame(
            $tenant->getTenantKey(),
            $activeTenant->getTenantKey(),
            'Tenancy singleton must reference the correct tenant despite the short-circuit',
        );

        // Confirm the UsesTenantConnection short-circuit is active: Account's getConnectionName()
        // returns null in testing, meaning queries go to the default connection.
        $accountModel = new Account();
        $this->assertNull(
            $accountModel->getConnectionName(),
            'UsesTenantConnection::getConnectionName() must return null in the testing environment, confirming the short-circuit is active',
        );

        $host->releaseAccountTenancy();
    }
}
