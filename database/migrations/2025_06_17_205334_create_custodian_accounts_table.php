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
        Schema::create('custodian_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Link to internal account
            $table->uuid('account_uuid');
            $table->foreign('account_uuid')->references('uuid')->on('accounts');

            // Custodian information
            $table->string('custodian_name'); // e.g., 'paysera', 'santander'
            $table->string('custodian_account_id'); // External account ID at custodian
            $table->string('custodian_account_name')->nullable(); // Name at custodian

            // Status and flags
            $table->enum('status', ['active', 'suspended', 'closed', 'pending'])->default('active');
            $table->boolean('is_primary')->default(false);

            // Additional data
            $table->json('metadata')->nullable(); // Store IBAN, BIC, and other custodian-specific data

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['account_uuid', 'custodian_name']);
            $table->index(['custodian_name', 'custodian_account_id']);
            $table->index(['account_uuid', 'is_primary']);
            $table->index('status');

            // Ensure only one primary per internal account
            $table->unique(['account_uuid', 'custodian_name', 'custodian_account_id'], 'custodian_accounts_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custodian_accounts');
    }
};
