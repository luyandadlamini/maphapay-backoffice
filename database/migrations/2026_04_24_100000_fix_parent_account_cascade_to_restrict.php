<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'parent_account_id')) {
                $table->uuid('parent_account_id')->nullable();
            }

            $foreignKeys = Schema::getForeignKeys('accounts');
            $hasFk = collect($foreignKeys)->contains(fn (array $fk): bool => in_array('parent_account_id', $fk['columns'] ?? [], true));

            if ($hasFk) {
                try {
                    $table->dropForeign(['parent_account_id']);
                } catch (Exception) {
                    // FK may not exist under this name
                }
            }

            $table->foreign('parent_account_id')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $foreignKeys = Schema::getForeignKeys('accounts');
            $hasFk = collect($foreignKeys)->contains(fn (array $fk): bool => in_array('parent_account_id', $fk['columns'] ?? [], true));

            if ($hasFk) {
                try {
                    $table->dropForeign(['parent_account_id']);
                } catch (Exception) {
                    // FK may not exist under this name
                }
            }

            $table->foreign('parent_account_id')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
        });
    }
};
