<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_merchant_bonus_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->unsignedBigInteger('merchant_partner_id');
            $table->uuid('minor_account_uuid');
            $table->uuid('parent_transaction_uuid');
            $table->integer('bonus_points_awarded');
            $table->decimal('multiplier_applied', 3, 2);
            $table->decimal('amount_szl', 12, 2);
            $table->enum('status', ['pending', 'awarded', 'failed'])->default('pending');
            $table->string('error_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->foreign('merchant_partner_id')->references('id')->on('merchant_partners')->onDelete('cascade');
            $table->index(['tenant_id', 'minor_account_uuid'], 'idx_bonus_tenant_minor');
            $table->index(['merchant_partner_id', 'created_at'], 'idx_bonus_merchant_date');
            $table->unique('parent_transaction_uuid', 'uniq_bonus_parent_trx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_merchant_bonus_transactions');
    }
};