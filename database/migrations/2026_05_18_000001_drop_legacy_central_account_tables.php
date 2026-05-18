<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // The 2026-05-17 rename of `accounts`/`account_balances` → `*_legacy_pre_canonicalization`
        // carried every inbound FK with it (MySQL retargets FKs on RENAME). Canonical
        // Accounts now live in tenant DBs, so any surviving central-DB FK to these
        // legacy tables is dead weight and must be dropped before the tables can go.
        // Known referrers at time of writing: `turnovers`, `stablecoin_collateral_positions`,
        // `custodian_accounts`, `custodian_transfers` (×2). Query information_schema
        // instead of hard-coding so the migration handles drift across environments.
        $legacyTables = [
            'accounts_legacy_pre_canonicalization',
            'account_balances_legacy_pre_canonicalization',
        ];

        $database = DB::connection('mysql')->getDatabaseName();

        $foreignKeys = DB::connection('mysql')
            ->table('information_schema.KEY_COLUMN_USAGE')
            ->select(['TABLE_NAME', 'CONSTRAINT_NAME'])
            ->where('CONSTRAINT_SCHEMA', $database)
            ->whereIn('REFERENCED_TABLE_NAME', $legacyTables)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->distinct()
            ->get();

        foreach ($foreignKeys as $fk) {
            DB::connection('mysql')->statement(sprintf(
                'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                $fk->TABLE_NAME,
                $fk->CONSTRAINT_NAME,
            ));
        }

        Schema::connection('mysql')->dropIfExists('account_balances_legacy_pre_canonicalization');
        Schema::connection('mysql')->dropIfExists('accounts_legacy_pre_canonicalization');
    }

    public function down(): void
    {
        // Intentionally irreversible. The legacy central tables were renamed from
        // accounts/account_balances on 2026-05-17, kept as a safety net during
        // the tenant-DB canonicalization, swept for orphan balances, and verified
        // empty. Re-creating them would resurrect a deprecated dual-write contract
        // that no application code targets. If a rollback ever proves necessary,
        // restore from the backup taken before this migration ran.
    }
};
