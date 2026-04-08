<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ledger_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('authorized_transaction_id')->nullable()->index();
            $table->string('authorized_transaction_trx', 32)->unique();
            $table->string('posting_type', 64)->index();
            $table->string('status', 32)->index();
            $table->string('asset_code', 16)->index();
            $table->string('transfer_reference', 64)->nullable()->index();
            $table->uuid('money_request_id')->nullable()->index();
            $table->unsignedInteger('rule_version')->default(1);
            $table->string('entries_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('authorized_transaction_id')
                ->references('id')
                ->on('authorized_transactions')
                ->nullOnDelete();
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ledger_posting_id')->index();
            $table->uuid('account_uuid')->nullable()->index();
            $table->string('asset_code', 16)->index();
            $table->bigInteger('signed_amount');
            $table->string('entry_type', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('ledger_posting_id')
                ->references('id')
                ->on('ledger_postings')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_postings');
    }
};
