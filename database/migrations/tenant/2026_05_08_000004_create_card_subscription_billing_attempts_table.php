<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_subscription_billing_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('card_subscription_id')
                ->constrained('card_subscriptions', indexName: 'card_bill_attempts_subscription_fk')
                ->cascadeOnDelete();
            $table->string('result', 16); // success | failed
            $table->string('failure_reason', 64)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('SZL');
            $table->uuid('idempotency_key')->nullable();
            $table->uuid('ledger_posting_id')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->index(['card_subscription_id', 'attempted_at'], 'card_bill_attempts_subscription_attempted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_subscription_billing_attempts');
    }
};
