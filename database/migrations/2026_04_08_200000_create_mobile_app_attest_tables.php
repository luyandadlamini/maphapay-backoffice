<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_attest_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('mobile_device_id');
            $table->string('key_id', 191);
            $table->string('status', 32)->default('active');
            $table->timestamp('attested_at')->nullable();
            $table->timestamp('last_assertion_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->cascadeOnDelete();
            $table->unique(['mobile_device_id', 'key_id'], 'mobile_app_attest_keys_device_key_unique');
            $table->index(['user_id', 'status'], 'mobile_app_attest_keys_user_status_index');
        });

        Schema::create('mobile_app_attest_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('mobile_device_id');
            $table->uuid('mobile_app_attest_key_id')->nullable();
            $table->string('purpose', 32);
            $table->string('key_id', 191)->nullable();
            $table->string('challenge_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('mobile_device_id')->references('id')->on('mobile_devices')->cascadeOnDelete();
            $table->foreign('mobile_app_attest_key_id')->references('id')->on('mobile_app_attest_keys')->nullOnDelete();
            $table->index(['mobile_device_id', 'purpose'], 'mobile_app_attest_challenges_device_purpose_index');
            $table->index(['user_id', 'expires_at'], 'mobile_app_attest_challenges_user_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_attest_challenges');
        Schema::dropIfExists('mobile_app_attest_keys');
    }
};
