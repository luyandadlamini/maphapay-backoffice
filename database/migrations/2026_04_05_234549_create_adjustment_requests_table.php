<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('adjustment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->index();
            $table->uuid('requester_id')->index();
            $table->uuid('reviewer_id')->nullable()->index();
            $table->string('type'); // 'credit' or 'debit'
            $table->decimal('amount', 18, 4);
            $table->text('reason');
            $table->string('status')->default('pending')->index(); // 'pending', 'approved', 'rejected'
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adjustment_requests');
    }
};
