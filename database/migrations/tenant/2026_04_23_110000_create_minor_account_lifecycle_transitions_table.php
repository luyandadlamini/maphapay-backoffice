<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('minor_account_lifecycle_transitions')) {
            return;
        }

        Schema::create('minor_account_lifecycle_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id');
            $table->uuid('minor_account_uuid');
            $table->string('transition_type', 100);
            $table->string('state', 50);
            $table->timestamp('effective_at');
            $table->timestamp('executed_at')->nullable();
            $table->string('blocked_reason_code', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'minor_account_uuid'], 'minor_lifecycle_transition_account_idx');
            $table->index(['state', 'effective_at'], 'minor_lifecycle_transition_state_idx');
            $table->unique(['minor_account_uuid', 'transition_type', 'effective_at'], 'minor_lifecycle_transition_replay_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_account_lifecycle_transitions');
    }
};
