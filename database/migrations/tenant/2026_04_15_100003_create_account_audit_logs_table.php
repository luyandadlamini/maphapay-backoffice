<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('account_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_uuid');
            $table->uuid('actor_user_uuid');
            $table->string('action'); // e.g. member.invited, role.changed, capability.unlocked
            $table->string('target_type')->nullable();
            $table->uuid('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['account_uuid', 'created_at']);
            $table->index('actor_user_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_audit_logs');
    }
};
