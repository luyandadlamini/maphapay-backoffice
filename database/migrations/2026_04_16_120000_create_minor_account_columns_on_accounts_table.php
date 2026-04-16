<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Add account_type ENUM column with default 'personal'
            $table->enum('account_type', ['personal', 'merchant', 'company', 'minor'])
                ->default('personal')
                ->after('name');

            // Add permission_level integer column (1-8 range, nullable for non-minor accounts)
            $table->integer('permission_level')
                ->nullable()
                ->after('account_type');

            // Add account_tier ENUM column (grow: 6-12, rise: 13-17, nullable for non-minor accounts)
            $table->enum('account_tier', ['grow', 'rise'])
                ->nullable()
                ->after('permission_level');

            // Add parent_account_id UUID column with foreign key to accounts(uuid) (CASCADE delete)
            $table->uuid('parent_account_id')
                ->nullable()
                ->after('account_tier');

            $table->foreign('parent_account_id')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Drop the foreign key constraint using raw SQL for compatibility
            try {
                DB::statement('ALTER TABLE accounts DROP FOREIGN KEY accounts_parent_account_id_foreign');
            } catch (\Exception) {
                // Foreign key might not exist or have a different name
            }

            // Drop columns in reverse order
            $table->dropColumn([
                'parent_account_id',
                'account_tier',
                'permission_level',
                'account_type',
            ]);
        });
    }
};
