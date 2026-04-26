<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_card_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('minor_account_uuid');
            $table->uuid('requested_by_user_uuid');
            $table->string('request_type');
            $table->string('status')->default('pending_approval');
            $table->string('requested_network')->default('visa');
            $table->decimal('requested_daily_limit', 12, 2)->nullable();
            $table->decimal('requested_monthly_limit', 12, 2)->nullable();
            $table->decimal('requested_single_limit', 12, 2)->nullable();
            $table->text('denial_reason')->nullable();
            $table->uuid('approved_by_user_uuid')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('minor_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');

            $table->index(['minor_account_uuid', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_card_requests');
    }
};
