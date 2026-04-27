<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The production `accounts` table was originally created from the 2024 non-tenant
 * migration which only had: id, uuid, name, user_uuid, balance, timestamps.
 *
 * Subsequent tenant migrations (`add_minor_account_columns`,
 * `add_minor_transition_columns`) assume `type` already exists and use it as an
 * AFTER anchor. Because `type` was never added to the live table those migrations
 * cannot run, and any query that references `accounts.type` throws a 500.
 *
 * This migration backfills all missing columns idempotently so both the login
 * flow and the minor-accounts feature work correctly.
 */
return new class () extends Migration {
    /**
     * Explicitly target the tenant connection so this migration runs against
     * the `main` database on Laravel Cloud regardless of DB_CONNECTION.
     */
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('accounts', function (Blueprint $table): void {
            // --- Core type column (blocks login; used in every Account query) ---
            if (! Schema::connection('tenant')->hasColumn('accounts', 'type')) {
                $table->string('type')->default('standard')->after('name');
            }

            // --- Minor-accounts columns (from add_minor_account_columns, Apr 16) ---
            // These use ->after('type') in the original migration; adding them here
            // after type exists avoids a duplicate-run failure if that migration
            // is recorded as done in the migrations table but never applied.
            if (! Schema::connection('tenant')->hasColumn('accounts', 'tier')) {
                $table->string('tier')->nullable()->after('type');
            }

            if (! Schema::connection('tenant')->hasColumn('accounts', 'permission_level')) {
                $table->integer('permission_level')->nullable()->after('tier');
            }

            if (! Schema::connection('tenant')->hasColumn('accounts', 'parent_account_id')) {
                $table->uuid('parent_account_id')->nullable()->after('user_uuid')->index();
            }

            // --- Lifecycle-transition columns (from add_minor_transition_columns, Apr 23) ---
            if (! Schema::connection('tenant')->hasColumn('accounts', 'minor_transition_state')) {
                $table->string('minor_transition_state')->nullable()->after('type');
            }

            if (! Schema::connection('tenant')->hasColumn('accounts', 'minor_transition_effective_at')) {
                $table->timestamp('minor_transition_effective_at')->nullable()->after('minor_transition_state');
            }
        });
    }

    public function down(): void
    {
        $columns = [
            'type',
            'tier',
            'permission_level',
            'parent_account_id',
            'minor_transition_state',
            'minor_transition_effective_at',
        ];

        Schema::connection('tenant')->table('accounts', function (Blueprint $table) use ($columns): void {
            foreach ($columns as $column) {
                if (Schema::connection('tenant')->hasColumn('accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
