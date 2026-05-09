<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('physical_card_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('card_subscription_id')->constrained('card_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('card_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->string('order_status', 32)->default('requested');
            // Allowed: requested, paid, approved, production, dispatched,
            //          ready_for_collection, delivered, activated, cancelled
            $table->string('delivery_method', 32);
            // Allowed: branch_collection, courier
            $table->json('delivery_address')->nullable();
            $table->uuid('collection_point_id')->nullable();
            $table->decimal('issuance_fee', 18, 2)->default(0);
            $table->decimal('delivery_fee', 18, 2)->default(0);
            $table->string('tracking_reference', 64)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('production_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('ready_for_collection_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'order_status']);
            $table->index(['card_subscription_id', 'order_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_card_orders');
    }
};
