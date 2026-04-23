<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('minor_family_support_transfers')) {
            return;
        }

        $hasLegacyIdempotencyUnique = Schema::hasIndex(
            'minor_family_support_transfers',
            'minor_family_support_transfers_idempotency_key_unique',
        );
        $hasScopedIdempotencyUnique = Schema::hasIndex(
            'minor_family_support_transfers',
            'minor_family_support_transfers_tenant_actor_idempotency_unique',
        );

        if (! $hasLegacyIdempotencyUnique && $hasScopedIdempotencyUnique) {
            return;
        }

        Schema::table('minor_family_support_transfers', function (Blueprint $table) use ($hasLegacyIdempotencyUnique, $hasScopedIdempotencyUnique): void {
            if ($hasLegacyIdempotencyUnique) {
                $table->dropUnique('minor_family_support_transfers_idempotency_key_unique');
            }

            if (! $hasScopedIdempotencyUnique) {
                $table->unique(
                    ['tenant_id', 'actor_user_uuid', 'idempotency_key'],
                    'minor_family_support_transfers_tenant_actor_idempotency_unique',
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('minor_family_support_transfers')) {
            return;
        }

        $hasLegacyIdempotencyUnique = Schema::hasIndex(
            'minor_family_support_transfers',
            'minor_family_support_transfers_idempotency_key_unique',
        );
        $hasScopedIdempotencyUnique = Schema::hasIndex(
            'minor_family_support_transfers',
            'minor_family_support_transfers_tenant_actor_idempotency_unique',
        );

        if (! $hasScopedIdempotencyUnique && $hasLegacyIdempotencyUnique) {
            return;
        }

        Schema::table('minor_family_support_transfers', function (Blueprint $table) use ($hasLegacyIdempotencyUnique, $hasScopedIdempotencyUnique): void {
            if ($hasScopedIdempotencyUnique) {
                $table->dropUnique('minor_family_support_transfers_tenant_actor_idempotency_unique');
            }

            if (! $hasLegacyIdempotencyUnique) {
                $table->unique('idempotency_key', 'minor_family_support_transfers_idempotency_key_unique');
            }
        });
    }
};
