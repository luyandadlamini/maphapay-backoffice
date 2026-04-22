<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_chore_completions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('chore_id')->index();
            $table->text('submission_note')->nullable();
            $table->string('status', 30)->default('pending_review'); // 'pending_review'|'approved'|'rejected'
            $table->uuid('reviewed_by_account_uuid')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('payout_processed_at')->nullable();
            $table->timestamps();

            $table->foreign('chore_id')
                ->references('id')
                ->on('minor_chores')
                ->onDelete('cascade');

            $table->foreign('reviewed_by_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_chore_completions');
    }
};
