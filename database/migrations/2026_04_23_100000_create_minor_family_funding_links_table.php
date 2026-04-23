<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_family_funding_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->uuid('minor_account_uuid');
            $table->uuid('created_by_user_uuid');
            $table->uuid('created_by_account_uuid');
            $table->string('title');
            $table->text('note')->nullable();
            $table->string('token', 128);
            $table->string('status', 32)->default('active');
            $table->string('amount_mode', 32)->default('fixed');
            $table->string('fixed_amount', 32)->nullable();
            $table->string('target_amount', 32)->nullable();
            $table->string('collected_amount', 32)->default('0');
            $table->string('asset_code', 16)->default('SZL');
            $table->json('provider_options')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_funded_at')->nullable();
            $table->timestamps();

            $table->unique('token', 'minor_family_funding_links_token_unique');
            $table->index(['tenant_id', 'minor_account_uuid', 'status'], 'minor_family_funding_links_tenant_minor_status_index');
            $table->index(['tenant_id', 'expires_at'], 'minor_family_funding_links_tenant_expires_at_index');
            $table->index('created_by_user_uuid', 'minor_family_funding_links_created_by_user_uuid_index');
            $table->index('created_by_account_uuid', 'minor_family_funding_links_created_by_account_uuid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_family_funding_links');
    }
};
