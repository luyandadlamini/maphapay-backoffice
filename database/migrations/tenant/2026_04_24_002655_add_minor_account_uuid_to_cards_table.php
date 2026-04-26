<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('cards')) {
            return;
        }

        if (Schema::hasColumn('cards', 'minor_account_uuid')) {
            return;
        }

        Schema::table('cards', function (Blueprint $table) {
            $table->uuid('minor_account_uuid')->nullable()->after('user_id');
            $table->foreign('minor_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
            $table->index(['minor_account_uuid', 'status']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cards') || ! Schema::hasColumn('cards', 'minor_account_uuid')) {
            return;
        }

        Schema::table('cards', function (Blueprint $table) {
            $table->dropForeign(['minor_account_uuid']);
            $table->dropColumn('minor_account_uuid');
        });
    }
};
