<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            try {
                $table->dropForeign(['parent_account_id']);
            } catch (\Exception $e) {
            }

            $table->foreignUuid('parent_account_id')
                ->nullable()
                ->change();

            $table->foreignUuid('parent_account_id')
                ->constrained('accounts')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropForeign(['parent_account_id']);

            $table->foreignUuid('parent_account_id')
                ->nullable()
                ->change();

            $table->foreignUuid('parent_account_id')
                ->constrained('accounts')
                ->onDelete('cascade');
        });
    }
};