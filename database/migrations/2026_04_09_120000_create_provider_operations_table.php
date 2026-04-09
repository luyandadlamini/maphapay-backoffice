<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('provider_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_family');
            $table->string('provider_name');
            $table->string('operation_type');
            $table->string('operation_key')->unique();
            $table->string('normalized_event_type')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('internal_reference')->nullable();
            $table->string('finality_status')->default('pending');
            $table->string('settlement_status')->default('pending');
            $table->string('reconciliation_status')->default('pending');
            $table->string('settlement_reference')->nullable();
            $table->string('reconciliation_reference')->nullable();
            $table->string('ledger_posting_reference')->nullable();
            $table->unsignedBigInteger('latest_webhook_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider_family', 'provider_name'], 'provider_operations_family_name_idx');
            $table->index(['provider_name', 'provider_reference'], 'provider_operations_name_reference_idx');
            $table->index(['operation_type', 'finality_status'], 'provider_operations_type_finality_idx');
            $table->index(['settlement_status', 'reconciliation_status'], 'provider_operations_settlement_reconciliation_idx');
            $table->foreign('latest_webhook_id')
                ->references('id')
                ->on('custodian_webhooks')
                ->nullOnDelete();
        });

        Schema::table('custodian_webhooks', function (Blueprint $table): void {
            $table->uuid('provider_operation_id')->nullable()->after('ledger_posting_reference');
            $table->index('provider_operation_id');
            $table->foreign('provider_operation_id')
                ->references('id')
                ->on('provider_operations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('custodian_webhooks', function (Blueprint $table): void {
            $table->dropForeign(['provider_operation_id']);
            $table->dropIndex(['provider_operation_id']);
            $table->dropColumn('provider_operation_id');
        });

        Schema::dropIfExists('provider_operations');
    }
};
