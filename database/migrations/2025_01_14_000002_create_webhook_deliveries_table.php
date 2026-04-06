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
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('webhook_uuid');
            $table->string('event_type');
            $table->json('payload');
            $table->integer('attempt_number')->default(1);
            $table->string('status')->default('pending'); // pending, delivered, failed
            $table->integer('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('next_retry_at')->nullable();
            $table->timestamps();

            $table->foreign('webhook_uuid')->references('uuid')->on('webhooks')->onDelete('cascade');
            $table->index(['webhook_uuid', 'status']);
            $table->index('event_type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
