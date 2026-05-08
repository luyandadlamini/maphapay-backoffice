<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();

            // Subscriber: the user whose cards are billed by this subscription
            $table->foreignUuid('subscriber_user_id')->constrained('users')->cascadeOnDelete();

            // Payer: the wallet that gets debited (= subscriber for adults; = guardian for minors)
            $table->foreignUuid('payer_user_id')->constrained('users')->cascadeOnDelete();

            // Plan
            $table->foreignUuid('card_plan_id')->constrained('card_plans');

            // Status
            $table->string('status', 32)->default('active');
            // Allowed: active, past_due, suspended, cancelled, pending_guardian_approval

            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_billing_date')->nullable();

            $table->unsignedInteger('failed_payment_count')->default(0);
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Minor metadata
            $table->boolean('is_minor_subscription')->default(false);
            $table->uuid('guardian_user_id')->nullable();
            $table->uuid('minor_account_uuid')->nullable();
            $table->uuid('minor_card_request_id')->nullable();

            $table->timestamps();

            $table->index(['subscriber_user_id', 'status']);
            $table->index(['payer_user_id', 'status']);
            $table->index(['next_billing_date', 'status']);
            $table->index('minor_account_uuid');

            // NOTE: Partial unique index (subscriber_user_id, status) WHERE status != 'cancelled'
            // is PostgreSQL-only. This uniqueness constraint is enforced at the application layer
            // in CardSubscriptionService::create() for MySQL compatibility.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_subscriptions');
    }
};
