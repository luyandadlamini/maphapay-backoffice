<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Account;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SweepOrphanCentralBalancesCommandTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return ['mysql', 'central'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // account_balances.asset_code has a FK to assets.code.
        // Seed the SZL asset so balance inserts don't violate the constraint.
        DB::table('assets')->insertOrIgnore([
            'code'         => 'SZL',
            'name'         => 'Swazi Lilangeni',
            'type'         => 'fiat',
            'precision'    => 2,
            'is_active'    => 1,
            'is_basket'    => 0,
            'is_tradeable' => 1,
            'metadata'     => '{}',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    #[Test]
    public function dry_run_lists_orphan_accounts_without_applying(): void
    {
        $user = User::factory()->create();

        $centralUuid = (string) Str::uuid();
        $tenantUuid = (string) Str::uuid(); // different from central — this is the orphan scenario

        // Seed a central account with a non-zero balance.
        DB::connection('mysql')->table('accounts')->insert([
            'uuid'       => $centralUuid,
            'name'       => 'Orphan Test Account',
            'user_uuid'  => $user->uuid,
            'balance'    => 0,
            'frozen'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('mysql')->table('account_balances')->insert([
            'account_uuid' => $centralUuid,
            'asset_code'   => 'SZL',
            'balance'      => 100_00, // 100.00 SZL in minor units
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Insert tenant + membership via raw SQL to bypass Stancl's CreateDatabase
        // DDL job, which invalidates MySQL prepared statement cache (error 1615).
        $tenantId = (string) Str::uuid();
        DB::connection('mysql')->table('tenants')->insert([
            'id'   => $tenantId,
            'name' => 'Test Tenant ' . $tenantId,
            'data' => json_encode(['tenancy_db_name' => 'tenant' . $tenantId]),
        ]);
        DB::connection('mysql')->table('account_memberships')->insert([
            'id'           => (string) Str::uuid(),
            'user_uuid'    => $user->uuid,
            'account_uuid' => $tenantUuid,
            'tenant_id'    => $tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Dry-run (default — no --apply flag).
        $exitCode = Artisan::call('maphapay:sweep-orphan-central-balances');
        $this->assertSame(0, $exitCode);

        // Balance must be untouched — dry-run must never write.
        $storedBalance = DB::connection('mysql')
            ->table('account_balances')
            ->where('account_uuid', $centralUuid)
            ->where('asset_code', 'SZL')
            ->value('balance');

        $this->assertEquals(100_00, $storedBalance, 'Dry-run must not alter the central balance');
    }

    #[Test]
    public function command_skips_rows_with_no_active_membership(): void
    {
        $user = User::factory()->create();

        $centralUuid = (string) Str::uuid();

        DB::connection('mysql')->table('accounts')->insert([
            'uuid'       => $centralUuid,
            'name'       => 'Orphan No Membership',
            'user_uuid'  => $user->uuid,
            'balance'    => 0,
            'frozen'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('mysql')->table('account_balances')->insert([
            'account_uuid' => $centralUuid,
            'asset_code'   => 'SZL',
            'balance'      => 50_00,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // No AccountMembership created for this user — command should warn and skip.
        $exitCode = Artisan::call('maphapay:sweep-orphan-central-balances');
        $this->assertSame(0, $exitCode);

        // Balance must remain unchanged.
        $storedBalance = DB::connection('mysql')
            ->table('account_balances')
            ->where('account_uuid', $centralUuid)
            ->where('asset_code', 'SZL')
            ->value('balance');

        $this->assertEquals(50_00, $storedBalance);
    }

    #[Test]
    public function command_skips_already_canonical_rows(): void
    {
        $user = User::factory()->create();

        // When central_uuid === membership account_uuid the row is already canonical.
        $sharedUuid = (string) Str::uuid();

        DB::connection('mysql')->table('accounts')->insert([
            'uuid'       => $sharedUuid,
            'name'       => 'Canonical Account',
            'user_uuid'  => $user->uuid,
            'balance'    => 0,
            'frozen'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('mysql')->table('account_balances')->insert([
            'account_uuid' => $sharedUuid,
            'asset_code'   => 'SZL',
            'balance'      => 75_00,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $tenantId = (string) Str::uuid();
        DB::connection('mysql')->table('tenants')->insert([
            'id'   => $tenantId,
            'name' => 'Test Tenant ' . $tenantId,
            'data' => json_encode(['tenancy_db_name' => 'tenant' . $tenantId]),
        ]);
        DB::connection('mysql')->table('account_memberships')->insert([
            'id'           => (string) Str::uuid(),
            'user_uuid'    => $user->uuid,
            'account_uuid' => $sharedUuid, // same UUID — already canonical
            'tenant_id'    => $tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $exitCode = Artisan::call('maphapay:sweep-orphan-central-balances');
        $this->assertSame(0, $exitCode); // "No orphan balances found."

        $storedBalance = DB::connection('mysql')
            ->table('account_balances')
            ->where('account_uuid', $sharedUuid)
            ->where('asset_code', 'SZL')
            ->value('balance');

        $this->assertEquals(75_00, $storedBalance, 'Canonical rows must not be touched');
    }
}
