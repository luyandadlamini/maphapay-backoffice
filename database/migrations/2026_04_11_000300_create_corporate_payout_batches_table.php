<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('corporate_payout_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('public_id', 64)->unique();
            $table->foreignUuid('corporate_profile_id')->constrained('corporate_profiles')->cascadeOnDelete();
            $table->string('status', 32)->default('draft');
            $table->bigInteger('total_amount_minor')->default(0);
            $table->string('asset_code', 16);
            $table->string('label')->nullable();
            $table->timestamp('cut_off_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approval_request_id')->nullable()
                ->constrained('corporate_action_approval_requests')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['corporate_profile_id', 'status'], 'corp_payout_batch_profile_status_idx');
        });

        Schema::create('corporate_payout_batch_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')
                ->constrained('corporate_payout_batches')
                ->cascadeOnDelete();
            $table->string('beneficiary_identifier', 255);
            $table->bigInteger('amount_minor');
            $table->string('asset_code', 16);
            $table->string('reference', 128);
            $table->string('status', 32)->default('pending');
            $table->text('error_reason')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'reference'], 'corp_payout_item_batch_ref_unique');
            $table->index(['batch_id', 'status'], 'corp_payout_item_batch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_payout_batch_items');
        Schema::dropIfExists('corporate_payout_batches');
    }
};
