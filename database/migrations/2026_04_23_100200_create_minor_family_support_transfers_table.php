<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_family_support_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->uuid('minor_account_uuid');
            $table->uuid('actor_user_uuid');
            $table->uuid('source_account_uuid');
            $table->string('status', 32)->default('pending_provider');
            $table->string('provider_name', 64);
            $table->string('recipient_name');
            $table->string('recipient_msisdn', 32);
            $table->string('amount', 32);
            $table->string('asset_code', 16)->default('SZL');
            $table->text('note')->nullable();
            $table->string('provider_reference_id', 128)->nullable();
            $table->uuid('mtn_momo_transaction_id')->nullable();
            $table->timestamp('wallet_refunded_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->string('idempotency_key', 191);
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'actor_user_uuid', 'idempotency_key'],
                'minor_family_support_transfers_tenant_actor_idempotency_unique',
            );
            $table->index(['tenant_id', 'minor_account_uuid', 'status'], 'minor_family_support_transfers_tenant_minor_status_index');
            $table->index('mtn_momo_transaction_id', 'minor_family_support_transfers_mtn_momo_transaction_id_index');
            $table->index('actor_user_uuid', 'minor_family_support_transfers_actor_user_uuid_index');
            $table->index('source_account_uuid', 'minor_family_support_transfers_source_account_uuid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_family_support_transfers');
    }
};
