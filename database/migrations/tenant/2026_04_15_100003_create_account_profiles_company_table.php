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
            $table->string('registration_number')->nullable();
            $table->string('industry');
            $table->string('company_size'); // small, medium, large, enterprise
            $table->string('settlement_method'); // maphapay_wallet, mobile_money, bank
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('account_uuid')->references('uuid')->on('accounts')->cascadeOnDelete();
            $table->index('account_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_profiles_company');
    }
};