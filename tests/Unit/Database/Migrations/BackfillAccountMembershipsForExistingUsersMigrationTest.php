<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Migrations;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;
use Throwable;

#[Large]
class BackfillAccountMembershipsForExistingUsersMigrationTest extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('central')->getPdo();
        } catch (Throwable $exception) {
            $this->markTestSkipped('Central database connection not available: ' . $exception->getMessage());
        }

        if (! Schema::connection('central')->hasTable('account_memberships')) {
            Artisan::call('migrate', [
                '--database' => 'central',
                '--force'    => true,
            ]);
        }
    }

#[Test]
    #[Ignore]
    public function it_backfills_missing_memberships_and_only_removes_its_own_rows_on_rollback(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Backfill Owner Team',
        ]);
        try {
            $tenant = Tenant::createFromTeam($team);
        } catch (QueryException $exception) {
            $this->markTestSkipped('Tenant database creation privileges are unavailable in this environment: ' . $exception->getMessage());
        }

        $tenantId = $tenant->id;

        $tenant->run(function () use ($user): void {
            DB::connection('tenant')->table('accounts')->insert([
                [
                    'uuid'                => 'acc-personal-backfill',
                    'user_uuid'           => $user->uuid,
                    'account_holder_uuid' => null,
                    'name'                => 'Personal Account',
                    'account_number'      => '8000000001',
                    'type'                => 'standard',
                    'currency'            => 'SZL',
                    'balance'             => 0,
                    'available_balance'   => 0,
                    'reserved_balance'    => 0,
                    'is_active'           => true,
                    'is_frozen'           => false,
                    'status'              => 'active',
                    'metadata'            => null,
                    'created_by'          => null,
                    'updated_by'          => null,
                    'frozen_by'           => null,
                    'created_at'          => now()->subDay(),
                    'updated_at'          => now()->subDay(),
                    'deleted_at'          => null,
                ],
                [
                    'uuid'                => 'acc-merchant-existing',
                    'user_uuid'           => $user->uuid,
                    'account_holder_uuid' => null,
                    'name'                => 'Merchant Account',
                    'account_number'      => '8000000002',
                    'type'                => 'merchant',
                    'currency'            => 'SZL',
                    'balance'             => 0,
                    'available_balance'   => 0,
                    'reserved_balance'    => 0,
                    'is_active'           => true,
                    'is_frozen'           => false,
                    'status'              => 'active',
                    'metadata'            => null,
                    'created_by'          => null,
                    'updated_by'          => null,
                    'frozen_by'           => null,
                    'created_at'          => now()->subDay(),
                    'updated_at'          => now()->subDay(),
                    'deleted_at'          => null,
                ],
            ]);
        });

        DB::connection('central')
            ->table('account_memberships')
            ->where('tenant_id', $tenantId)
            ->delete();

        $initialCount = AccountMembership::query()->count();

        $migration = require base_path('database/migrations/2026_04_15_200000_backfill_account_memberships_for_existing_users.php');
        $migration->up();

        $backfilled = AccountMembership::query()
            ->where('tenant_id', $tenantId)
            ->get();

        $this->assertSame($initialCount + 2, AccountMembership::query()->count());

        $personalMembership = $backfilled->firstWhere('account_uuid', 'acc-personal-backfill');
        $this->assertNotNull($personalMembership);
        $this->assertSame('personal', $personalMembership->account_type);
        $this->assertSame('owner', $personalMembership->role);
        $this->assertSame('active', $personalMembership->status);
        $this->assertSame(
            '2026_04_15_200000_backfill_account_memberships_for_existing_users',
            $personalMembership->permissions_override['backfill_migration'] ?? null,
        );

        $merchantMembership = $backfilled->firstWhere('account_uuid', 'acc-merchant-existing');
        $this->assertNotNull($merchantMembership);
        $this->assertSame('merchant', $merchantMembership->account_type);

        $migration->down();

        $remainingCount = AccountMembership::query()->count();
        $this->assertSame($initialCount, $remainingCount, 'down() should only remove memberships this migration created');
    }
}
