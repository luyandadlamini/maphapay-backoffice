<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('audit_id')->unique();
            $table->string('agent_did', 255)->index();
            $table->string('tool_name', 100)->index();
            $table->json('input_data')->nullable();
            $table->json('result_summary')->nullable();
            $table->string('status', 50)->index();
            $table->text('error_message')->nullable();
            $table->decimal('execution_time_ms', 10, 2)->nullable();
            $table->dateTime('created_at')->useCurrent()->index();
            $table->dateTime('updated_at')->nullable();

            // Composite index for common queries
            $table->index(['agent_did', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_mcp_audit_logs');
    }
};
