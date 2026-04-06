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
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('url');
            $table->json('events'); // Array of event types to subscribe to
            $table->json('headers')->nullable(); // Custom headers for the webhook
            $table->string('secret')->nullable(); // Secret for webhook signature verification
            $table->boolean('is_active')->default(true);
            $table->integer('retry_attempts')->default(3);
            $table->integer('timeout_seconds')->default(30);
            $table->dateTime('last_triggered_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('last_failure_at')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
