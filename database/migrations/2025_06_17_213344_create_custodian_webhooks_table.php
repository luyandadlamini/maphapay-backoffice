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
        Schema::create('custodian_webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Custodian information
            $table->string('custodian_name');
            $table->string('event_type'); // e.g., 'transaction.completed', 'account.updated'
            $table->string('event_id')->nullable(); // External event ID from custodian

            // Webhook data
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->string('signature')->nullable();

            // Processing status
            $table->enum('status', ['pending', 'processing', 'processed', 'failed', 'ignored']);
            $table->integer('attempts')->default(0);
            $table->dateTime('processed_at')->nullable();
            $table->text('error_message')->nullable();

            // Related entities
            $table->uuid('custodian_account_id')->nullable();
            $table->string('transaction_id')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['custodian_name', 'event_type']);
            $table->index('status');
            $table->index('processed_at');
            $table->unique(['custodian_name', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custodian_webhooks');
    }
};
