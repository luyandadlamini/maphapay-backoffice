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
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->onDelete('cascade');
            $table->uuid('user_uuid');
            $table->json('selected_options'); // Array of selected option IDs
            $table->integer('voting_power')->default(1);
            $table->dateTime('voted_at');
            $table->string('signature', 255)->nullable(); // For vote verification
            $table->json('metadata')->nullable(); // Additional vote data
            $table->timestamps();

            // Unique constraint to prevent double voting
            $table->unique(['poll_id', 'user_uuid'], 'unique_user_poll_vote');

            // Indexes
            $table->index(['poll_id', 'voted_at']);
            $table->index(['user_uuid']);
            $table->index(['voting_power']);

            // Foreign key constraint for user_uuid (references users.uuid)
            $table->foreign('user_uuid')->references('uuid')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
