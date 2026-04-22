<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add guardian and co_guardian to the set of valid roles on account_memberships.
 *
 * The role column is a plain VARCHAR (no native PostgreSQL ENUM type), so we
 * manage allowed values through a CHECK constraint.  Adding values is done by
 * dropping the old constraint (if present) and re-creating it with the expanded
 * list; the down() method restores the previous constraint.
 *
 * Existing roles:  owner | admin | finance_manager | maker | approver | viewer
 * After this migration:  + guardian | co_guardian
 */
return new class () extends Migration {
    /**
     * The connection to use for this migration (central / landlord DB).
     */
    protected $connection = 'central';

    /**
     * Valid role values before this migration.
     *
     * @var string[]
     */
    private array $previousRoles = [
        'owner',
        'admin',
        'finance_manager',
        'maker',
        'approver',
        'viewer',
    ];

    /**
     * Valid role values after this migration.
     *
     * @var string[]
     */
    private array $newRoles = [
        'owner',
        'admin',
        'finance_manager',
        'maker',
        'approver',
        'viewer',
        'guardian',
        'co_guardian',
    ];

    public function up(): void
    {
        // Drop the old constraint if it exists (idempotent, cross-DB compatible).
        $this->dropConstraintIfExists('account_memberships_role_check');

        $list = implode("', '", $this->newRoles);

        DB::connection($this->connection)->statement(
            "ALTER TABLE account_memberships ADD CONSTRAINT account_memberships_role_check
             CHECK (role IN ('{$list}'))"
        );
    }

    public function down(): void
    {
        // Restore the constraint to the pre-guardian set.
        // NOTE: Any rows with role='guardian' or role='co_guardian' must be
        // removed before rolling back this migration or the constraint will fail.
        $this->dropConstraintIfExists('account_memberships_role_check');

        $list = implode("', '", $this->previousRoles);

        DB::connection($this->connection)->statement(
            "ALTER TABLE account_memberships ADD CONSTRAINT account_memberships_role_check
             CHECK (role IN ('{$list}'))"
        );
    }

    /**
     * Drop a named CHECK constraint if it exists, using a cross-database approach
     * that works on both PostgreSQL and MySQL (including versions before 8.0.29
     * which do not support DROP CONSTRAINT IF EXISTS).
     */
    private function dropConstraintIfExists(string $constraintName): void
    {
        $connection = DB::connection($this->connection);
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $connection->statement(
                "ALTER TABLE account_memberships DROP CONSTRAINT IF EXISTS {$constraintName}"
            );

            return;
        }

        // MySQL / MariaDB: check information_schema before dropping.
        $exists = $connection->selectOne(
            "SELECT CONSTRAINT_NAME
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA   = DATABASE()
                AND TABLE_NAME     = 'account_memberships'
                AND CONSTRAINT_NAME = ?
                AND CONSTRAINT_TYPE = 'CHECK'",
            [$constraintName]
        );

        if ($exists) {
            $connection->statement(
                "ALTER TABLE account_memberships DROP CONSTRAINT {$constraintName}"
            );
        }
    }
};
