<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // The `turnovers` table still references the legacy accounts table via the
        // `turnovers_account` foreign key (the constraint followed the 2026-05-17
        // rename from `accounts` → `accounts_legacy_pre_canonicalization`). Canonical
        // Accounts now live in tenant DBs, so the central-DB FK is dead weight —
        // drop it before removing the legacy table. `turnovers.account_uuid` is
        // retained as a plain UUID reference.
        if (Schema::connection('mysql')->hasTable('turnovers')) {
            Schema::connection('mysql')->table('turnovers', function (Blueprint $table) {
                try {
                    $table->dropForeign('turnovers_account');
                } catch (\Throwable) {
                    // FK already dropped in a prior partial run — safe to ignore.
                }
            });
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
