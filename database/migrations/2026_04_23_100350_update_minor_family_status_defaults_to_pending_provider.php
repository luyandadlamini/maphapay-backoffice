<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('minor_family_funding_attempts') && Schema::hasColumn('minor_family_funding_attempts', 'status')) {
            DB::statement(
                "ALTER TABLE minor_family_funding_attempts MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending_provider'",
            );
        }

        if (Schema::hasTable('minor_family_support_transfers') && Schema::hasColumn('minor_family_support_transfers', 'status')) {
            DB::statement(
                "ALTER TABLE minor_family_support_transfers MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending_provider'",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('minor_family_funding_attempts') && Schema::hasColumn('minor_family_funding_attempts', 'status')) {
            DB::statement(
                "ALTER TABLE minor_family_funding_attempts MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'",
            );
        }

        if (Schema::hasTable('minor_family_support_transfers') && Schema::hasColumn('minor_family_support_transfers', 'status')) {
            DB::statement(
                "ALTER TABLE minor_family_support_transfers MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'",
            );
        }
    }
};
