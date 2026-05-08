<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_disputes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('card_transaction_id')->constrained('card_transactions')->cascadeOnDelete();
            $table->string('reason', 32);
            // Allowed: unrecognised, duplicate, wrong_amount, service_not_received, other
            $table->string('status', 16)->default('submitted');
            // Allowed: submitted, in_review, evidence_required, won, lost, withdrawn
            $table->text('user_description')->nullable();
            $table->json('evidence')->nullable();
            $table->decimal('disputed_amount', 18, 2)->nullable();
            $table->string('currency', 3)->default('SZL');
            $table->string('processor_dispute_id', 128)->nullable()->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processor_acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('card_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_disputes');
    }
};
