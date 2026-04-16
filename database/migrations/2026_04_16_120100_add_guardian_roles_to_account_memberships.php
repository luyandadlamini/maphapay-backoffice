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
        // Drop the old constraint if it exists (idempotent).
        DB::statement(
            'ALTER TABLE account_memberships DROP CONSTRAINT IF EXISTS account_memberships_role_check'
        );

        $list = implode("', '", $this->newRoles);

        DB::statement(
            "ALTER TABLE account_memberships ADD CONSTRAINT account_memberships_role_check
             CHECK (role IN ('{$list}'))"
        );
    }

    public function down(): void
    {
        // Restore the constraint to the pre-guardian set.
        // NOTE: Any rows with role='guardian' or role='co_guardian' must be
        // removed before rolling back this migration or the constraint will fail.
        DB::statement(
            'ALTER TABLE account_memberships DROP CONSTRAINT IF EXISTS account_memberships_role_check'
        );

        $list = implode("', '", $this->previousRoles);

        DB::statement(
            "ALTER TABLE account_memberships ADD CONSTRAINT account_memberships_role_check
             CHECK (role IN ('{$list}'))"
        );
    }
};
