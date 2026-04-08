<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('admin_action_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('workspace');
            $table->string('action');
            $table->string('status')->default('pending');
            $table->text('reason');
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type')->nullable();
            $table->string('target_identifier')->nullable();
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace', 'status']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_action_approval_requests');
    }
};
