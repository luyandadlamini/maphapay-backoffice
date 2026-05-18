<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
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
