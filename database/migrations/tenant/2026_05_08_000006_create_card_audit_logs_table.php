<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_audit_logs', function (Blueprint $table): void {
            // Append-only table — application MUST NOT issue UPDATE or DELETE.

            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();

            $table->string('actor_type', 16);
            // Allowed: user, admin, system, processor

            $table->uuid('actor_id')->nullable();

            $table->string('action', 96);
            // Examples: subscription.created, card.frozen_by_user, card.reveal_requested

            $table->string('entity_type', 64);
            $table->uuid('entity_id')->nullable();

            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('metadata')->nullable();

            $table->string('ip_address', 64)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('user_agent', 256)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_audit_logs');
    }
};
