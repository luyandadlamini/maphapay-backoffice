<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-specific migration for asset transfer projection tables.
 *
 * This migration runs in tenant database context, creating the read model used
 * for aggregate-backed asset transfers. Event-sourced transfer history remains
 * in the event store and must not share this table name.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('from_account_uuid')->index();
            $table->uuid('to_account_uuid')->index();
            $table->string('from_asset_code', 20)->default('USD');
            $table->string('to_asset_code', 20)->default('USD');
            $table->unsignedBigInteger('from_amount');
            $table->unsignedBigInteger('to_amount');
            $table->string('status')->default('initiated');
            $table->string('reference')->nullable()->index();
            $table->string('transfer_id')->nullable()->index();
            $table->string('hash', 128)->nullable()->index();
            $table->string('description')->nullable();
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('initiated_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['from_account_uuid', 'to_account_uuid']);
        });

        Schema::create('transfer_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('aggregate_uuid')->index();
            $table->unsignedInteger('aggregate_version');
            $table->json('state');
            $table->timestamps();

            $table->unique(['aggregate_uuid', 'aggregate_version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_snapshots');
        Schema::dropIfExists('asset_transfers');
    }
};
