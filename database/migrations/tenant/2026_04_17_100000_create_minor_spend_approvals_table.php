<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_spend_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // The minor account that initiated the spend
            $table->uuid('minor_account_uuid')->index();
            // The primary guardian who must approve
            $table->uuid('guardian_account_uuid')->index();
            // Original send-money payload fields
            $table->uuid('from_account_uuid');
            $table->uuid('to_account_uuid');
            $table->string('amount');          // major-unit string e.g. "150.00"
            $table->string('asset_code', 10)->default('SZL');
            $table->string('note')->nullable();
            $table->string('merchant_category')->default('general');
            // Workflow
            $table->enum('status', ['pending', 'approved', 'declined', 'cancelled'])->default('pending');
            $table->timestamp('expires_at');   // now() + 24 hours
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['minor_account_uuid', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_spend_approvals');
    }
};
