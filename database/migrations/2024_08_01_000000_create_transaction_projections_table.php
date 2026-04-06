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
        if (Schema::hasTable('transaction_projections')) {
            return;
        }

        Schema::create('transaction_projections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('account_uuid');
            $table->string('asset_code', 10)->default('USD');
            $table->bigInteger('amount');
            $table->string('type');
            $table->string('subtype')->nullable();
            $table->string('description')->nullable();
            $table->string('reference')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('hash', 128);
            $table->json('metadata')->nullable();
            $table->string('status')->default('completed');
            $table->uuid('related_account_uuid')->nullable();
            $table->string('transaction_group_uuid')->nullable();
            $table->uuid('parent_transaction_id')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->dateTime('retried_at')->nullable();
            $table->uuid('retry_transaction_id')->nullable();
            $table->timestamps();

            // Indexes (excluding status-related ones that are added in a later migration)
            $table->index(['account_uuid', 'created_at']);
            $table->index(['account_uuid', 'asset_code']);
            $table->index(['type', 'created_at']);
            $table->index('hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_projections');
    }
};
