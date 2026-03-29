<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('operation_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation_type');
            $table->string('idempotency_key', 255);
            $table->string('payload_hash', 64); // SHA-256 hex digest of normalized payload.
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->json('result_payload')->nullable(); // Cached handler result for idempotent replays.
            $table->timestamps();

            // Uniqueness enforced at DB level — primary guard against concurrent duplicate execution.
            $table->unique(
                ['user_id', 'operation_type', 'idempotency_key'],
                'op_records_user_type_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_records');
    }
};
