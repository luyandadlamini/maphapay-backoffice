<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('minor_account_lifecycle_exceptions')) {
            return;
        }

        Schema::create('minor_account_lifecycle_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->uuid('minor_account_uuid');
            $table->uuid('transition_id')->nullable();
            $table->string('reason_code', 100);
            $table->string('status', 50);
            $table->string('source', 100);
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('sla_escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'sla_due_at'], 'minor_lifecycle_exception_sla_idx');
            $table->index(['minor_account_uuid', 'reason_code', 'status'], 'minor_lifecycle_exception_reason_idx');
            $table->index(['tenant_id', 'status'], 'minor_lifecycle_exception_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_account_lifecycle_exceptions');
    }
};
