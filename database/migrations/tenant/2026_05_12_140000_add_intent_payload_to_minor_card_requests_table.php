<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('minor_card_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('minor_card_requests', 'intent_payload')) {
                $table->json('intent_payload')->nullable()->after('requested_single_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('minor_card_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('minor_card_requests', 'intent_payload')) {
                $table->dropColumn('intent_payload');
            }
        });
    }
};
