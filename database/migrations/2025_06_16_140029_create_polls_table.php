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
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 50); // PollType enum values
            $table->json('options'); // Array of PollOption objects
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->string('status', 20)->default('draft'); // PollStatus enum values
            $table->integer('required_participation')->nullable(); // Minimum percentage required
            $table->string('voting_power_strategy', 100)->default('one_user_one_vote');
            $table->string('execution_workflow', 255)->nullable(); // Workflow class to execute on success
            $table->uuid('created_by');
            $table->json('metadata')->nullable(); // Additional configuration
            $table->timestamps();

            // Indexes
            $table->index(['status', 'start_date', 'end_date']);
            $table->index(['created_by']);
            $table->index(['voting_power_strategy']);

            // Foreign key constraint for created_by (references users.uuid)
            $table->foreign('created_by')->references('uuid')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('polls');
    }
};
