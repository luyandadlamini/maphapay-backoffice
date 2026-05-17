<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->rename('accounts', 'accounts_legacy_pre_canonicalization');
        Schema::connection('mysql')->rename('account_balances', 'account_balances_legacy_pre_canonicalization');
    }

    public function down(): void
    {
        Schema::connection('mysql')->rename('accounts_legacy_pre_canonicalization', 'accounts');
        Schema::connection('mysql')->rename('account_balances_legacy_pre_canonicalization', 'account_balances');
    }
};
