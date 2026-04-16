<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateMinorAccountColumnsTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function it_creates_minor_account_columns_with_correct_constraints(): void
    {
        // Drop columns if they exist from a previous test run
        $this->dropMinorAccountColumns();

        // Run the migration
        $migration = require base_path('database/migrations/2026_04_16_120000_create_minor_account_columns_on_accounts_table.php');
        $migration->up();

        // Assert all columns exist
        $this->assertTrue(Schema::hasColumns('accounts', [
            'account_type',
            'permission_level',
            'account_tier',
            'parent_account_id',
        ]));

        // Assert account_type ENUM column
        $this->assertColumnExists('accounts', 'account_type');
        $this->assertColumnType('accounts', 'account_type', 'enum');

        // Assert permission_level integer column
        $this->assertColumnExists('accounts', 'permission_level');
        $this->assertColumnNullable('accounts', 'permission_level');

        // Assert account_tier ENUM column
        $this->assertColumnExists('accounts', 'account_tier');
        $this->assertColumnType('accounts', 'account_tier', 'enum');
        $this->assertColumnNullable('accounts', 'account_tier');

        // Assert parent_account_id UUID column
        $this->assertColumnExists('accounts', 'parent_account_id');
        $this->assertColumnNullable('accounts', 'parent_account_id');

        // Verify foreign key constraint exists
        $this->assertForeignKeyExists('accounts', 'parent_account_id');
    }

    #[Test]
    public function it_rolls_back_migration_successfully(): void
    {
        // Run the migration
        $migration = require base_path('database/migrations/2026_04_16_120000_create_minor_account_columns_on_accounts_table.php');
        $migration->up();

        // Verify columns exist
        $this->assertTrue(Schema::hasColumns('accounts', [
            'account_type',
            'permission_level',
            'account_tier',
            'parent_account_id',
        ]));

        // Rollback the migration
        $migration->down();

        // Verify columns no longer exist
        $this->assertFalse(Schema::hasColumn('accounts', 'account_type'));
        $this->assertFalse(Schema::hasColumn('accounts', 'permission_level'));
        $this->assertFalse(Schema::hasColumn('accounts', 'account_tier'));
        $this->assertFalse(Schema::hasColumn('accounts', 'parent_account_id'));
    }

    protected function tearDown(): void
    {
        $this->dropMinorAccountColumns();

        parent::tearDown();
    }

    private function dropMinorAccountColumns(): void
    {
        // First, drop the foreign key constraint if it exists using raw SQL
        if (Schema::hasColumn('accounts', 'parent_account_id')) {
            try {
                // Get the database connection
                $connection = Schema::getConnection();
                // Query to find and drop the foreign key
                $connection->statement(
                    'ALTER TABLE accounts DROP FOREIGN KEY accounts_parent_account_id_foreign'
                );
            } catch (\Exception) {
                // Constraint might not exist or name might be different, continue
            }
        }

        // Then drop the columns
        Schema::table('accounts', function ($table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('accounts', 'parent_account_id')) {
                $columnsToDrop[] = 'parent_account_id';
            }
            if (Schema::hasColumn('accounts', 'account_tier')) {
                $columnsToDrop[] = 'account_tier';
            }
            if (Schema::hasColumn('accounts', 'permission_level')) {
                $columnsToDrop[] = 'permission_level';
            }
            if (Schema::hasColumn('accounts', 'account_type')) {
                $columnsToDrop[] = 'account_type';
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    private function assertColumnExists(string $table, string $column): void
    {
        $this->assertTrue(
            Schema::hasColumn($table, $column),
            "Column '$column' does not exist on table '$table'."
        );
    }

    private function assertColumnType(string $table, string $column, string $type): void
    {
        $this->assertColumnExists($table, $column);

        $columns = Schema::getColumns($table);
        $columnInfo = collect($columns)->firstWhere('name', $column);

        $this->assertNotNull(
            $columnInfo,
            "Could not retrieve column information for '$column' on table '$table'."
        );

        $this->assertStringContainsString(
            $type,
            $columnInfo['type'],
            "Column '$column' type is '{$columnInfo['type']}', expected to contain '$type'."
        );
    }

    private function assertColumnNullable(string $table, string $column): void
    {
        $this->assertColumnExists($table, $column);

        $columns = Schema::getColumns($table);
        $columnInfo = collect($columns)->firstWhere('name', $column);

        $this->assertNotNull(
            $columnInfo,
            "Could not retrieve column information for '$column' on table '$table'."
        );

        $this->assertTrue(
            $columnInfo['nullable'],
            "Column '$column' on table '$table' is not nullable."
        );
    }

    private function assertForeignKeyExists(string $table, string $column): void
    {
        $this->assertColumnExists($table, $column);

        $foreignKeys = Schema::getForeignKeys($table);

        $this->assertNotEmpty(
            $foreignKeys,
            "No foreign keys found on table '$table'."
        );

        $parentAccountFk = collect($foreignKeys)->first(function ($fk) use ($column) {
            return in_array($column, (array) ($fk['columns'] ?? $fk['local'] ?? []));
        });

        $this->assertNotNull(
            $parentAccountFk,
            "Foreign key for column '$column' does not exist on table '$table'."
        );

        // Log the structure for debugging
        // echo "\nForeign Key Structure: " . json_encode($parentAccountFk, JSON_PRETTY_PRINT);

        // Verify it references accounts.uuid
        $this->assertNotNull(
            $parentAccountFk['foreign_columns'] ?? null,
            "Foreign key does not have 'foreign_columns' property."
        );

        // foreign_columns is an array of column names referenced
        $referencedColumns = (array) ($parentAccountFk['foreign_columns'] ?? []);
        $this->assertContains(
            'uuid',
            $referencedColumns,
            "Foreign key for column '$column' does not reference 'uuid'. Referenced columns: " . implode(', ', $referencedColumns)
        );

        // Verify on delete action is cascade
        $onDelete = $parentAccountFk['on_delete'] ?? null;
        $this->assertNotNull(
            $onDelete,
            "Could not determine onDelete action for foreign key on '$column'."
        );
        $this->assertSame(
            'cascade',
            $onDelete,
            "Foreign key for column '$column' has onDelete='$onDelete', expected 'cascade'."
        );
    }
}
