<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align the tenant transaction_projections schema with the domain model.
 *
 * The tenant migration (0001_01_01_000002) created transaction_projections
 * with an older shape that is missing several columns expected by
 * TransactionProjection model and TransactionHistoryController:
 *   - uuid            (model primary for HasUuids; tenant had transaction_uuid)
 *   - subtype         (domain sub-classification)
 *   - asset_code      (tenant had 'currency')
 *   - hash            (dedup field)
 *   - external_reference
 *   - analytics / categorisation columns
 *   - related_account_uuid, transaction_group_uuid, parent_transaction_id
 *   - cancelled_at, cancelled_by, retried_at, retry_transaction_id
 *
 * This migration adds the missing columns without touching existing rows'
 * data and backfills uuid / asset_code from the existing columns.
 */
return new class () extends Migration {
    public function getConnection(): string
    {
        return 'tenant';
    }

    public function up(): void
    {
        Schema::connection('tenant')->table('transaction_projections', function (Blueprint $table): void {
            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id');
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'asset_code')) {
                $table->string('asset_code', 10)->nullable()->after('account_uuid');
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'subtype')) {
                $table->string('subtype')->nullable()->after('type');
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'hash')) {
                $table->string('hash', 128)->nullable()->after('metadata');
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'external_reference')) {
                $table->string('external_reference')->nullable()->after('reference');
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'analytics_bucket')) {
                $table->string('analytics_bucket')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'budget_eligible')) {
                $table->boolean('budget_eligible')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'source_domain')) {
                $table->string('source_domain')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'system_category_slug')) {
                $table->string('system_category_slug')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'user_category_slug')) {
                $table->string('user_category_slug')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'effective_category_slug')) {
                $table->string('effective_category_slug')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'categorization_source')) {
                $table->string('categorization_source')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'related_account_uuid')) {
                $table->uuid('related_account_uuid')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'transaction_group_uuid')) {
                $table->string('transaction_group_uuid')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'parent_transaction_id')) {
                $table->uuid('parent_transaction_id')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'cancelled_at')) {
                $table->dateTime('cancelled_at')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'cancelled_by')) {
                $table->uuid('cancelled_by')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'retried_at')) {
                $table->dateTime('retried_at')->nullable();
            }

            if (! Schema::connection('tenant')->hasColumn('transaction_projections', 'retry_transaction_id')) {
                $table->uuid('retry_transaction_id')->nullable();
            }
        });

        // Backfill uuid from transaction_uuid (the column name used in the old tenant schema)
        if (Schema::connection('tenant')->hasColumn('transaction_projections', 'transaction_uuid')) {
            DB::connection('tenant')
                ->table('transaction_projections')
                ->whereNull('uuid')
                ->update(['uuid' => DB::connection('tenant')->raw('transaction_uuid')]);
        }

        // Backfill asset_code from currency (the column name used in the old tenant schema)
        if (Schema::connection('tenant')->hasColumn('transaction_projections', 'currency')) {
            DB::connection('tenant')
                ->table('transaction_projections')
                ->whereNull('asset_code')
                ->update(['asset_code' => DB::connection('tenant')->raw('currency')]);

            // Default to SZL where currency was also null
            DB::connection('tenant')
                ->table('transaction_projections')
                ->whereNull('asset_code')
                ->update(['asset_code' => 'SZL']);
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('transaction_projections', function (Blueprint $table): void {
            $dropColumns = [
                'uuid', 'asset_code', 'subtype', 'hash', 'external_reference',
                'analytics_bucket', 'budget_eligible', 'source_domain',
                'system_category_slug', 'user_category_slug', 'effective_category_slug',
                'categorization_source', 'related_account_uuid', 'transaction_group_uuid',
                'parent_transaction_id', 'cancelled_at', 'cancelled_by',
                'retried_at', 'retry_transaction_id',
            ];

            foreach ($dropColumns as $col) {
                if (Schema::connection('tenant')->hasColumn('transaction_projections', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
