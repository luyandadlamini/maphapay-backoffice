<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('corporate_treasury_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('corporate_profile_id')->constrained('corporate_profiles')->cascadeOnDelete();
            $table->string('treasury_account_id', 64);
            $table->string('account_type', 32);
            $table->string('asset_code', 16)->nullable();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['corporate_profile_id', 'treasury_account_id'], 'corp_treasury_acct_unique');
            $table->index(['corporate_profile_id', 'account_type'], 'corp_treasury_acct_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_treasury_accounts');
    }
};
