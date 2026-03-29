<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        try {
            Schema::connection('legacy')->create('migration_delta_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('legacy_user_id')->index();
                $table->string('currency', 3)->default('SZL');
                $table->decimal('amount_major', 20, 4);
                $table->string('direction', 8);
                $table->string('legacy_trx_id')->nullable()->unique();
                $table->string('legacy_table', 32)->nullable();
                $table->timestamp('legacy_created_at');
                $table->timestamp('captured_at')->useCurrent();
                $table->text('meta')->nullable();
            });
        } catch (Throwable) {
            // Legacy DB not available — this migration only applies to the legacy MySQL DB,
            // not to FinAegis's own SQLite/MySQL test/production DBs.
        }
    }

    public function down(): void
    {
        try {
            Schema::connection('legacy')->dropIfExists('migration_delta_log');
        } catch (Throwable) {
        }
    }
};
