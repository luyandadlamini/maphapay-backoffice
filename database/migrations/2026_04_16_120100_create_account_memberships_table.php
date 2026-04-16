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
        Schema::create('account_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('minor_account_id');
            $table->uuid('guardian_account_id');
            $table->enum('role', ['guardian', 'co_guardian'])->default('guardian');
            $table->json('permissions')->nullable();
            $table->timestamps();

            // Foreign key constraints with cascade delete
            $table->foreign('minor_account_id')
                ->references('uuid')
                ->on('accounts')
                ->cascadeOnDelete();

            $table->foreign('guardian_account_id')
                ->references('uuid')
                ->on('accounts')
                ->cascadeOnDelete();

            // Unique constraint: prevent duplicate guardian relationships
            $table->unique(['minor_account_id', 'guardian_account_id', 'role']);

            // Indexes for efficient querying
            $table->index('minor_account_id');
            $table->index('guardian_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_memberships');
    }
};
