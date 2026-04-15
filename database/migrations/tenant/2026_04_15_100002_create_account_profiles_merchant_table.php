<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('account_profiles_merchant', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_uuid');
            $table->string('trade_name');
            $table->string('merchant_category');
            $table->string('location')->nullable();
            $table->string('classification')->default('informal'); // informal, sole_proprietor, registered_business
            $table->string('settlement_method')->default('maphapay_wallet'); // maphapay_wallet, mobile_money, bank
            $table->uuid('settlement_wallet_link_id')->nullable();
            $table->string('qr_code_payload')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('account_uuid')->references('uuid')->on('accounts')->cascadeOnDelete();
            $table->index('account_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_profiles_merchant');
    }
};
