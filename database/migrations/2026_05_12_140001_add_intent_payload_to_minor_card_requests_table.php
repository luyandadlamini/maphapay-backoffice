<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors tenant migration `2026_05_12_140000_add_intent_payload_to_minor_card_requests_table.php`
 * so local / CI default connections that host `minor_card_requests` pick up the column.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('minor_card_requests')) {
            return;
        }

        Schema::table('minor_card_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('minor_card_requests', 'intent_payload')) {
                $table->json('intent_payload')->nullable()->after('requested_single_limit');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('minor_card_requests')) {
            return;
        }

        Schema::table('minor_card_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('minor_card_requests', 'intent_payload')) {
                $table->dropColumn('intent_payload');
            }
        });
    }
};
