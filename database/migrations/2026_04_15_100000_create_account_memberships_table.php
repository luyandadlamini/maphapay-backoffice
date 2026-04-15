<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('account_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_uuid');
            $table->string('tenant_id');
            $table->uuid('account_uuid');
            $table->string('account_type')->default('personal'); // personal, merchant, company
            $table->string('role')->default('owner'); // owner, admin, finance_manager, maker, approver, viewer
            $table->string('status')->default('active'); // active, invited, suspended, removed
            $table->uuid('invited_by')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->json('permissions_override')->nullable();
            $table->timestamps();

            $table->foreign('user_uuid')->references('uuid')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->index(['user_uuid', 'status']);
            $table->index(['account_uuid', 'status']);
            $table->index(['tenant_id', 'account_uuid']);
            $table->unique(['user_uuid', 'tenant_id', 'account_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_memberships');
    }
};
