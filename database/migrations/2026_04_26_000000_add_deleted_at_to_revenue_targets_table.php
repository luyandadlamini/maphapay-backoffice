<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('revenue_targets') && ! Schema::hasColumn('revenue_targets', 'deleted_at')) {
            Schema::table('revenue_targets', function (Blueprint $table): void {
                $table->softDeletesTz()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('revenue_targets') && Schema::hasColumn('revenue_targets', 'deleted_at')) {
            Schema::table('revenue_targets', function (Blueprint $table): void {
                $table->dropSoftDeletesTz();
            });
        }
    }
};
