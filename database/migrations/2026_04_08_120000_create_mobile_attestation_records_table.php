<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mobile_attestation_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->uuid('mobile_device_id')->nullable();
            $table->string('action', 120);
            $table->string('decision', 30);
            $table->string('reason', 120);
            $table->boolean('attestation_enabled')->default(false);
            $table->boolean('attestation_verified')->default(false);
            $table->string('device_type', 30)->nullable();
            $table->string('device_id', 150)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('request_path', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action']);
            $table->index(['decision', 'created_at']);
            $table->index(['mobile_device_id']);
            $table->index(['payload_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_attestation_records');
    }
};
