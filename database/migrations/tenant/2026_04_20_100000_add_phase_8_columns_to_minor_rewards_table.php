<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Alter the existing minor_rewards table to add Phase 8 columns
        Schema::table('minor_rewards', function (Blueprint $table) {
            if (! Schema::hasColumn('minor_rewards', 'category')) {
                $table->string('category', 50)->nullable()->after('name');
            }
            if (! Schema::hasColumn('minor_rewards', 'image_url')) {
                $table->string('image_url', 2048)->nullable()->after('description');
            }
            if (! Schema::hasColumn('minor_rewards', 'price_points')) {
                $table->integer('price_points')->notNullable()->after('image_url');
            }
            if (! Schema::hasColumn('minor_rewards', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('stock');
            }
            if (! Schema::hasColumn('minor_rewards', 'partner_id')) {
                $table->unsignedBigInteger('partner_id')->nullable()->after('is_featured');
            }
            if (! Schema::hasColumn('minor_rewards', 'expiry_date')) {
                $table->timestamp('expiry_date')->nullable()->after('partner_id');
            }
            if (! Schema::hasColumn('minor_rewards', 'age_restriction')) {
                $table->string('age_restriction', 50)->nullable()->after('expiry_date');
            }
        });

        // Create foreign key constraint if it doesn't exist
        if (! Schema::hasColumn('minor_rewards', 'partner_id')) {
            return;
        }

        // Check if foreign key already exists by trying to add and catching the error
        try {
            Schema::table('minor_rewards', function (Blueprint $table) {
                $table->foreign('partner_id')
                    ->references('id')
                    ->on('merchant_partners')
                    ->onDelete('set null');
            });
        } catch (Exception) {
            // Foreign key constraint may already exist, which is fine
        }

        // Add indices for commonly queried columns
        Schema::table('minor_rewards', function (Blueprint $table) {
            if (! Schema::hasIndex('minor_rewards', 'minor_rewards_category_index')) {
                $table->index('category');
            }
            if (! Schema::hasIndex('minor_rewards', 'minor_rewards_is_featured_index')) {
                $table->index('is_featured');
            }
            if (! Schema::hasIndex('minor_rewards', 'minor_rewards_stock_index')) {
                $table->index('stock');
            }
        });
    }

    public function down(): void
    {
        Schema::table('minor_rewards', function (Blueprint $table) {
            // Drop foreign key if exists
            try {
                $table->dropForeign(['partner_id']);
            } catch (Exception) {
                // Foreign key doesn't exist, which is fine
            }

            // Drop indices
            try {
                $table->dropIndex('minor_rewards_category_index');
            } catch (Exception) {
                // Index doesn't exist, which is fine
            }

            try {
                $table->dropIndex('minor_rewards_is_featured_index');
            } catch (Exception) {
                // Index doesn't exist, which is fine
            }

            try {
                $table->dropIndex('minor_rewards_stock_index');
            } catch (Exception) {
                // Index doesn't exist, which is fine
            }

            // Drop columns if they exist
            $columnsToRemove = [
                'category',
                'image_url',
                'price_points',
                'is_featured',
                'partner_id',
                'expiry_date',
                'age_restriction',
            ];

            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('minor_rewards', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
