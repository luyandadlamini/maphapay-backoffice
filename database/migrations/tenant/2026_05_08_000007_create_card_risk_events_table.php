<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('card_risk_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('card_id')->nullable()->constrained('cards')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('severity', 16);
            // Allowed: low, medium, high, critical
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 16)->default('open');
            // Allowed: open, in_review, resolved, dismissed
            $table->uuid('assigned_to_admin_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['severity', 'status']);
            $table->index(['card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_risk_events');
    }
};
