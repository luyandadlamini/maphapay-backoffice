<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('account_profiles_company', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_uuid');
            $table->string('company_name');
            $table->string('business_type'); // pty_ltd, public, sole_trader, informal
            $table->string('registration_number')->nullable(); // Company registration number or TIN
            $table->string('tin_number')->nullable(); // Tax Identification Number (10 digits)
            $table->string('industry')->nullable(); // Required for formal, optional for informal
            $table->string('company_size')->nullable(); // Required for formal, optional for informal
            $table->string('settlement_method'); // maphapay_wallet, mobile_money, bank
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('kyb_status')->default('pending');
            $table->timestamp('kyb_submitted_at')->nullable();
            $table->timestamp('kyb_verified_at')->nullable();
            $table->text('kyb_rejection_reason')->nullable();
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->timestamps();

            $table->foreign('account_uuid')->references('uuid')->on('accounts')->cascadeOnDelete();
            $table->index('account_uuid');
            $table->index('tin_number');
            $table->index('registration_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_profiles_company');
    }
};
