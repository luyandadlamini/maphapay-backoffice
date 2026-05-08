<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_fees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete(); // payer
            $table->uuid('related_entity_id')->nullable()->index();
            $table->string('related_entity_type', 64)->nullable();

            $table->string('fee_type', 32);
            // Allowed: subscription, fx_markup, atm, virtual_card_replacement,
            //          physical_card_issuance, physical_card_replacement,
            //          chargeback_abuse, manual_adjustment

            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('SZL');

            $table->string('status', 16)->default('pending');
            // Allowed: pending, charged, waived, refunded, failed

            $table->uuid('ledger_posting_id')->nullable();
            $table->timestamp('charged_at')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'fee_type', 'status']);
            $table->index(['related_entity_type', 'related_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_fees');
    }
};
