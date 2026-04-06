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
        Schema::table('agents', function (Blueprint $table) {
            // Additional KYC/Compliance fields
            if (! Schema::hasColumn('agents', 'kyc_verification_level')) {
                $table->string('kyc_verification_level')->nullable()->after('kyc_status');
            }
            if (! Schema::hasColumn('agents', 'kyc_expires_at')) {
                $table->dateTime('kyc_expires_at')->nullable()->after('kyc_verified_at');
            }

            // Risk and compliance
            if (! Schema::hasColumn('agents', 'risk_score')) {
                $table->integer('risk_score')->default(0)->after('kyc_expires_at');
            }
            if (! Schema::hasColumn('agents', 'compliance_flags')) {
                $table->json('compliance_flags')->nullable()->after('risk_score');
            }

            // Transaction volume and limits
            if (! Schema::hasColumn('agents', 'total_transaction_volume')) {
                $table->decimal('total_transaction_volume', 20, 2)->default(0)->after('total_transactions');
            }
            if (! Schema::hasColumn('agents', 'daily_transaction_limit')) {
                $table->decimal('daily_transaction_limit', 20, 2)->nullable()->after('total_transaction_volume');
            }
            if (! Schema::hasColumn('agents', 'weekly_transaction_limit')) {
                $table->decimal('weekly_transaction_limit', 20, 2)->nullable()->after('daily_transaction_limit');
            }
            if (! Schema::hasColumn('agents', 'monthly_transaction_limit')) {
                $table->decimal('monthly_transaction_limit', 20, 2)->nullable()->after('weekly_transaction_limit');
            }
            if (! Schema::hasColumn('agents', 'limit_currency')) {
                $table->string('limit_currency', 3)->default('USD')->after('monthly_transaction_limit');
            }
            if (! Schema::hasColumn('agents', 'limits_updated_at')) {
                $table->dateTime('limits_updated_at')->nullable()->after('limit_currency');
            }

            // Geographic information
            if (! Schema::hasColumn('agents', 'country_code')) {
                $table->string('country_code', 2)->nullable()->after('limits_updated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $columns = [
                'kyc_verification_level',
                'kyc_expires_at',
                'risk_score',
                'compliance_flags',
                'total_transaction_volume',
                'daily_transaction_limit',
                'weekly_transaction_limit',
                'monthly_transaction_limit',
                'limit_currency',
                'limits_updated_at',
                'country_code',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('agents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
