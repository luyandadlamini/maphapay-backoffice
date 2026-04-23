<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('mtn_momo_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('mtn_momo_transactions', 'context_type')) {
                $table->string('context_type', 64)->nullable();
            }

            if (! Schema::hasColumn('mtn_momo_transactions', 'context_uuid')) {
                $table->uuid('context_uuid')->nullable();
            }
        });

        Schema::table('mtn_momo_transactions', function (Blueprint $table): void {
            if (! Schema::hasIndex('mtn_momo_transactions', 'mtn_momo_transactions_context_type_context_uuid_index')) {
                $table->index(['context_type', 'context_uuid'], 'mtn_momo_transactions_context_type_context_uuid_index');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('mtn_momo_transactions', 'mtn_momo_transactions_context_type_context_uuid_index')) {
            Schema::table('mtn_momo_transactions', function (Blueprint $table): void {
                $table->dropIndex('mtn_momo_transactions_context_type_context_uuid_index');
            });
        }

        Schema::table('mtn_momo_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('mtn_momo_transactions', 'context_uuid')) {
                $table->dropColumn('context_uuid');
            }

            if (Schema::hasColumn('mtn_momo_transactions', 'context_type')) {
                $table->dropColumn('context_type');
            }
        });
    }
};
