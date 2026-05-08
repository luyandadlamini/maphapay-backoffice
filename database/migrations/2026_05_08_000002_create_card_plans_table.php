<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
            $table->decimal('monthly_fee', 18, 2)->default(0);
            $table->unsignedInteger('max_virtual_cards')->default(0);
            $table->unsignedInteger('max_physical_cards')->default(0);
            $table->unsignedInteger('monthly_card_creation_limit')->default(0);
            $table->unsignedInteger('free_virtual_reissues_per_month')->default(0);
            $table->decimal('virtual_card_replacement_fee', 18, 2)->default(0);
            $table->decimal('monthly_card_spend_limit', 18, 2)->default(0);
            $table->decimal('daily_card_spend_limit', 18, 2)->default(0);
            $table->decimal('single_transaction_limit', 18, 2)->default(0);
            $table->boolean('atm_enabled')->default(false);
            $table->decimal('atm_daily_limit', 18, 2)->default(0);
            $table->decimal('atm_monthly_limit', 18, 2)->default(0);
            $table->decimal('atm_fixed_fee', 18, 2)->default(0);
            $table->unsignedInteger('atm_percentage_fee_bps')->default(0);
            $table->unsignedInteger('fx_markup_bps')->default(0);
            $table->decimal('physical_card_issuance_fee', 18, 2)->default(0);
            $table->decimal('physical_card_replacement_fee', 18, 2)->default(0);
            $table->string('eligibility', 16)->default('adult'); // adult | minor
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['eligibility', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_plans');
    }
};
