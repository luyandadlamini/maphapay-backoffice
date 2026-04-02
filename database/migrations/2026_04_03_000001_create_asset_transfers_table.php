<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference')->nullable()->index();
            $table->string('transfer_id')->nullable()->index();
            $table->string('hash', 128)->nullable()->index();
            $table->uuid('from_account_uuid')->index();
            $table->uuid('to_account_uuid')->index();
            $table->string('from_asset_code', 20)->index();
            $table->string('to_asset_code', 20)->index();
            $table->unsignedBigInteger('from_amount');
            $table->unsignedBigInteger('to_amount');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('status')->default('initiated');
            $table->string('description')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['from_account_uuid', 'status'], 'asset_transfers_from_status_idx');
            $table->index(['to_account_uuid', 'status'], 'asset_transfers_to_status_idx');
            $table->index(['created_at', 'status'], 'asset_transfers_created_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_transfers');
    }
};
