<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('key', 128)->unique();
            $table->string('request_hash', 64);
            $table->string('endpoint', 96);
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('status', 16)->default('processing');
            // Allowed: processing, completed, failed
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
