<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_family_reconciliation_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('mtn_momo_transaction_id');
            $table->string('reason_code', 64);
            $table->string('status', 32)->default('open');
            $table->string('source', 32)->default('unknown');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(
                ['mtn_momo_transaction_id', 'reason_code'],
                'minor_family_recon_exceptions_txn_reason_unique'
            );
            $table->index(['status', 'last_seen_at'], 'minor_family_recon_exceptions_status_last_seen_index');
            $table->index('source', 'minor_family_recon_exceptions_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_family_reconciliation_exceptions');
    }
};
