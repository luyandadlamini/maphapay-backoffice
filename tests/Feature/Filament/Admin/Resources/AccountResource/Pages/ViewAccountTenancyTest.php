<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources\AccountResource\Pages;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Resources\AccountResource\Pages\ViewAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;
use Throwable;

class ViewAccountTenancyTest extends TestCase
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
                'ViewAccount tenancy tests require MySQL (SQLite :memory: cannot share tables across connections)'
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
    private function seedTenantDirectly(string $tenantId): Tenant
    {
        $central = DB::connection('central');

        if (! $central->table('tenants')->where('id', $tenantId)->exists()) {
            $central->table('tenants')->insert([
                'id'            => $tenantId,
                'name'          => 'ViewAccount test tenant',
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
    public function viewing_an_account_initializes_tenancy_for_that_accounts_tenant(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('finance-lead');
        $this->actingAs($admin);

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

        $account = Account::create([
            'uuid'      => $accountUuid,
            'user_uuid' => $userUuid,
            'name'      => 'Test Wallet',
        ]);

        Livewire::test(ViewAccount::class, ['record' => $account->getKey()])
            ->assertSuccessful();

        $this->assertTrue(app(Tenancy::class)->initialized);

        $activeTenant = app(Tenancy::class)->tenant;
        $this->assertInstanceOf(Tenant::class, $activeTenant);
        $this->assertSame($tenant->id, $activeTenant->getTenantKey());
    }
}
