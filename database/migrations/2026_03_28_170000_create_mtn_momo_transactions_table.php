<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mtn_momo_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 191);
            $table->string('type', 32);
            $table->string('amount', 32);
            $table->string('currency', 8);
            $table->string('status', 32);
            $table->string('party_msisdn', 32);
            $table->string('mtn_reference_id', 64)->nullable()->unique();
            $table->string('mtn_financial_transaction_id', 128)->nullable();
            $table->text('note')->nullable();
            $table->string('last_mtn_status', 64)->nullable();
            $table->timestamp('wallet_credited_at')->nullable();
            $table->timestamp('wallet_debited_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtn_momo_transactions');
    }
};
