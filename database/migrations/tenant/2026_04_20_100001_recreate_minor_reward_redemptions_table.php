<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Drop the old table if it exists (from Phase 4)
        Schema::dropIfExists('minor_reward_redemptions');

        // Create the new Phase 8 table with extended semantics
        Schema::create('minor_reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('minor_account_id');
            $table->uuid('reward_id');
            $table->enum('status', ['awaiting_approval', 'approved', 'processing', 'in_transit', 'delivered', 'redeemed', 'cancelled', 'failed', 'expired'])
                ->default('processing');
            $table->integer('points_redeemed');
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('shipping_address_id')->nullable();
            $table->string('delivery_method', 50)->nullable(); // 'sms', 'in_app', 'physical', 'qr_code'
            $table->string('merchant_reference', 255)->nullable();
            $table->string('tracking_number', 255)->nullable();
            $table->string('child_phone_number', 20)->nullable();
            $table->timestamp('expires_at')->nullable(); // Approval timeout
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('minor_account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('reward_id')->references('id')->on('minor_rewards')->onDelete('restrict');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_reward_redemptions');
    }
};
