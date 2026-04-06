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
        // EDD Workflows table
        Schema::create('edd_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('customer_id', 36)->index();
            $table->string('status', 50)->index();
            $table->string('risk_category', 50)->index();
            $table->json('workflow_data');
            $table->timestamps();

            // Composite index for common queries
            $table->index(['customer_id', 'status']);
        });

        // EDD Periodic Reviews table
        Schema::create('edd_periodic_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id', 36)->unique();
            $table->dateTime('next_review_at')->index();
            $table->integer('interval_months')->default(6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edd_periodic_reviews');
        Schema::dropIfExists('edd_workflows');
    }
};
