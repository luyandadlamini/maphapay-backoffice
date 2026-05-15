<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Widen `messages.idempotency_key` from VARCHAR(36) to VARCHAR(64) so the
     * deterministic keys used by the chat-sync service fit (e.g.
     * `mr:{uuid}:declined` is 45 chars).
     *
     * The original column already carries a UNIQUE index. Schema::table()
     * with `->change()` on MySQL re-creates the index, which fails with
     * `Duplicate key name 'messages_idempotency_key_unique'` because the index
     * still exists. Using raw `ALTER TABLE ... MODIFY` widens the column in
     * place and leaves the index alone.
     *
     * On SQLite (used in some tests) the column is text-typed so widening is
     * a no-op; just skip rather than emit unsupported syntax.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `messages` MODIFY `idempotency_key` VARCHAR(64) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `messages` MODIFY `idempotency_key` VARCHAR(36) NULL');
        }
    }
};
