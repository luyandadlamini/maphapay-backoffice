<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('custodian_webhooks', function (Blueprint $table): void {
            $table->string('normalized_event_type')->nullable()->after('event_type');
            $table->string('provider_reference')->nullable()->after('event_id');
            $table->string('payload_hash', 64)->nullable()->after('payload');
            $table->string('dedupe_key')->nullable()->after('payload_hash');
            $table->string('finality_status')->default('pending')->after('status');
            $table->string('settlement_status')->default('pending')->after('finality_status');
            $table->string('reconciliation_status')->default('pending')->after('settlement_status');
            $table->string('settlement_reference')->nullable()->after('transaction_id');
            $table->string('reconciliation_reference')->nullable()->after('settlement_reference');
            $table->string('ledger_posting_reference')->nullable()->after('reconciliation_reference');

            $table->index('normalized_event_type');
            $table->index('provider_reference');
            $table->index('payload_hash');
            $table->unique('dedupe_key');
            $table->index(['finality_status', 'settlement_status', 'reconciliation_status'], 'custodian_webhooks_state_idx');
        });
    }

    public function down(): void
    {
        Schema::table('custodian_webhooks', function (Blueprint $table): void {
            $table->dropUnique(['dedupe_key']);
            $table->dropIndex(['normalized_event_type']);
            $table->dropIndex(['provider_reference']);
            $table->dropIndex(['payload_hash']);
            $table->dropIndex('custodian_webhooks_state_idx');

            $table->dropColumn([
                'normalized_event_type',
                'provider_reference',
                'payload_hash',
                'dedupe_key',
                'finality_status',
                'settlement_status',
                'reconciliation_status',
                'settlement_reference',
                'reconciliation_reference',
                'ledger_posting_reference',
            ]);
        });
    }
};
