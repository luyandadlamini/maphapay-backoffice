<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_family_funding_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->uuid('funding_link_uuid');
            $table->uuid('minor_account_uuid');
            $table->string('status', 32)->default('pending_provider');
            $table->string('sponsor_name');
            $table->string('sponsor_msisdn', 32);
            $table->string('amount', 32);
            $table->string('asset_code', 16)->default('SZL');
            $table->string('provider_name', 64);
            $table->string('provider_reference_id', 128)->nullable();
            $table->uuid('mtn_momo_transaction_id')->nullable();
            $table->timestamp('wallet_credited_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->string('dedupe_hash', 64);
            $table->timestamps();

            $table->unique('dedupe_hash', 'minor_family_funding_attempts_dedupe_hash_unique');
            $table->index(['tenant_id', 'minor_account_uuid', 'status'], 'minor_family_funding_attempts_tenant_minor_status_index');
            $table->index('funding_link_uuid', 'minor_family_funding_attempts_funding_link_uuid_index');
            $table->index('mtn_momo_transaction_id', 'minor_family_funding_attempts_mtn_momo_transaction_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_family_funding_attempts');
    }
};
