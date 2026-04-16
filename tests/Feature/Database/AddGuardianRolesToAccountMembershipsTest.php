<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Tests\CreatesApplication;

/**
 * Verifies that the 2026_04_16_120100 migration correctly extends the
 * account_memberships role column to accept 'guardian' and 'co_guardian'
 * while preserving all existing role values.
 *
 * NOTE on rollback: The down() method restores the old CHECK constraint.
 * Because the role column is a plain VARCHAR (not a native PostgreSQL ENUM),
 * removing added values IS possible via rollback — we test this below.
 * If you are running on a database that uses native ENUMs, rolling back
 * added ENUM values is not supported by PostgreSQL; in that case, skip or
 * comment out the rollback test.
 */
class AddGuardianRolesToAccountMembershipsTest extends BaseTestCase
{
    use CreatesApplication;

    /** @var array<string> Rows inserted during each test, cleaned up in tearDown */
    private array $insertedIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('central')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Central database connection not available: ' . $e->getMessage());
        }

        if (! Schema::connection('central')->hasTable('account_memberships')) {
            Artisan::call('migrate', [
                '--database' => 'central',
                '--force'    => true,
            ]);
        }

        // Apply the migration under test so each test starts from its "up" state.
        $this->runMigrationUp();
    }

    protected function tearDown(): void
    {
        // Remove rows inserted by tests.
        if (! empty($this->insertedIds)) {
            DB::connection('central')
                ->table('account_memberships')
                ->whereIn('id', $this->insertedIds)
                ->delete();
        }

        // Drop the constraint so the test database is not permanently altered.
        DB::connection('central')->statement(
            'ALTER TABLE account_memberships DROP CONSTRAINT IF EXISTS account_memberships_role_check'
        );

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function runMigrationUp(): void
    {
        $migration = require base_path(
            'database/migrations/2026_04_16_120100_add_guardian_roles_to_account_memberships.php'
        );
        $migration->up();
    }

    private function runMigrationDown(): void
    {
        $migration = require base_path(
            'database/migrations/2026_04_16_120100_add_guardian_roles_to_account_memberships.php'
        );
        $migration->down();
    }

    /**
     * Insert a minimal account_memberships row for testing; tracks the id for cleanup.
     */
    private function insertMembershipRow(string $role): string
    {
        $id = (string) Uuid::uuid4();

        DB::connection('central')->table('account_memberships')->insert([
            'id'           => $id,
            'user_uuid'    => (string) Uuid::uuid4(),
            'tenant_id'    => 'test-tenant-' . substr($id, 0, 8),
            'account_uuid' => (string) Uuid::uuid4(),
            'role'         => $role,
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->insertedIds[] = $id;

        return $id;
    }

    // -------------------------------------------------------------------------
    // Tests: new roles are accepted
    // -------------------------------------------------------------------------

    #[Test]
    public function it_accepts_guardian_as_a_valid_role(): void
    {
        $id = $this->insertMembershipRow('guardian');

        $row = DB::connection('central')
            ->table('account_memberships')
            ->where('id', $id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('guardian', $row->role);
    }

    #[Test]
    public function it_accepts_co_guardian_as_a_valid_role(): void
    {
        $id = $this->insertMembershipRow('co_guardian');

        $row = DB::connection('central')
            ->table('account_memberships')
            ->where('id', $id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('co_guardian', $row->role);
    }

    // -------------------------------------------------------------------------
    // Tests: existing roles still work
    // -------------------------------------------------------------------------

    #[Test]
    public function it_still_accepts_the_owner_role(): void
    {
        $id = $this->insertMembershipRow('owner');

        $this->assertSame(
            'owner',
            DB::connection('central')->table('account_memberships')->where('id', $id)->value('role')
        );
    }

    #[Test]
    public function it_still_accepts_the_admin_role(): void
    {
        $id = $this->insertMembershipRow('admin');

        $this->assertSame(
            'admin',
            DB::connection('central')->table('account_memberships')->where('id', $id)->value('role')
        );
    }

    #[Test]
    public function it_still_accepts_finance_manager_maker_approver_and_viewer_roles(): void
    {
        foreach (['finance_manager', 'maker', 'approver', 'viewer'] as $role) {
            $id = $this->insertMembershipRow($role);

            $this->assertSame(
                $role,
                DB::connection('central')->table('account_memberships')->where('id', $id)->value('role'),
                "Role '{$role}' was not stored correctly."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Tests: rollback removes the new roles from the constraint
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_guardian_role_after_rollback(): void
    {
        // Remove any guardian rows we inserted before rolling back.
        DB::connection('central')
            ->table('account_memberships')
            ->whereIn('id', $this->insertedIds)
            ->where('role', 'guardian')
            ->delete();

        $this->runMigrationDown();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // This insert should now fail the CHECK constraint.
        DB::connection('central')->table('account_memberships')->insert([
            'id'           => (string) Uuid::uuid4(),
            'user_uuid'    => (string) Uuid::uuid4(),
            'tenant_id'    => 'test-rollback-guardian',
            'account_uuid' => (string) Uuid::uuid4(),
            'role'         => 'guardian',
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    #[Test]
    public function it_rejects_co_guardian_role_after_rollback(): void
    {
        // Remove any co_guardian rows we inserted before rolling back.
        DB::connection('central')
            ->table('account_memberships')
            ->whereIn('id', $this->insertedIds)
            ->where('role', 'co_guardian')
            ->delete();

        $this->runMigrationDown();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('central')->table('account_memberships')->insert([
            'id'           => (string) Uuid::uuid4(),
            'user_uuid'    => (string) Uuid::uuid4(),
            'tenant_id'    => 'test-rollback-co-guardian',
            'account_uuid' => (string) Uuid::uuid4(),
            'role'         => 'co_guardian',
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
