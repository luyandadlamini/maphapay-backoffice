<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('exchange_rates')) {
            // Table doesn't exist, skip this migration
            return;
        }

        Schema::table('exchange_rates', function (Blueprint $table) {
            // Add bid/ask columns if they don't exist
            if (! Schema::hasColumn('exchange_rates', 'bid')) {
                $table->decimal('bid', 20, 10)->nullable()->after('rate');
            }

            if (! Schema::hasColumn('exchange_rates', 'ask')) {
                $table->decimal('ask', 20, 10)->nullable()->after('bid');
            }

            // Add index for historical queries
            $table->index(['from_asset_code', 'to_asset_code', 'created_at'], 'exchange_rates_historical_idx');

            // Add index for active rates
            $table->index(['is_active', 'from_asset_code', 'to_asset_code'], 'exchange_rates_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropIndex('exchange_rates_historical_idx');
            $table->dropIndex('exchange_rates_active_idx');

            if (Schema::hasColumn('exchange_rates', 'bid')) {
                $table->dropColumn('bid');
            }

            if (Schema::hasColumn('exchange_rates', 'ask')) {
                $table->dropColumn('ask');
            }
        });
    }
};
