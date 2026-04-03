<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('authorized_transaction_biometric_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('authorized_transaction_id');
            $table->uuid('mobile_device_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('challenge', 64)->unique();
            $table->enum('status', ['pending', 'verified', 'expired', 'failed'])->default('pending');
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('authorized_transaction_id')
                ->references('id')
                ->on('authorized_transactions')
                ->cascadeOnDelete();

            $table->foreign('mobile_device_id')
                ->references('id')
                ->on('mobile_devices')
                ->cascadeOnDelete();

            $table->index(['authorized_transaction_id', 'status'], 'auth_txn_bio_challenge_txn_status');
            $table->index(['mobile_device_id', 'status'], 'auth_txn_bio_challenge_device_status');
            $table->index(['challenge', 'status'], 'auth_txn_bio_challenge_value_status');
            $table->index('expires_at', 'auth_txn_bio_challenge_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorized_transaction_biometric_challenges');
    }
};
