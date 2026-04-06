<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('transaction_projections', function (Blueprint $table): void {
            if (! Schema::hasColumn('transaction_projections', 'analytics_bucket')) {
                $table->string('analytics_bucket')->nullable()->after('status');
            }

            if (! Schema::hasColumn('transaction_projections', 'budget_eligible')) {
                $table->boolean('budget_eligible')->nullable()->after('analytics_bucket');
            }

            if (! Schema::hasColumn('transaction_projections', 'source_domain')) {
                $table->string('source_domain')->nullable()->after('budget_eligible');
            }

            if (! Schema::hasColumn('transaction_projections', 'system_category_slug')) {
                $table->string('system_category_slug')->nullable()->after('source_domain');
            }

            if (! Schema::hasColumn('transaction_projections', 'user_category_slug')) {
                $table->string('user_category_slug')->nullable()->after('system_category_slug');
            }

            if (! Schema::hasColumn('transaction_projections', 'effective_category_slug')) {
                $table->string('effective_category_slug')->nullable()->after('user_category_slug');
            }

            if (! Schema::hasColumn('transaction_projections', 'categorization_source')) {
                $table->string('categorization_source')->nullable()->after('effective_category_slug');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_projections', function (Blueprint $table): void {
            $columns = [
                'analytics_bucket',
                'budget_eligible',
                'source_domain',
                'system_category_slug',
                'user_category_slug',
                'effective_category_slug',
                'categorization_source',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('transaction_projections', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
