<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('authorized_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The operation type key — drives handler dispatch in AuthorizedTransactionManager.
            // Values: send_money | scheduled_send | request_money | request_money_received
            $table->string('remark', 64)->index();

            // Short unique reference returned to the mobile app and used for polling.
            $table->string('trx', 32)->unique();

            // Normalized request payload (amount always stored as major-unit string).
            $table->json('payload');

            // State machine: pending → completed | failed | expired | cancelled
            $table->string('status', 16)->default('pending')->index();

            // Result stored on completion so re-plays return the same response.
            $table->json('result')->nullable();

            // Verification method chosen by the user.
            $table->string('verification_type', 16)->nullable(); // otp | pin | none

            // OTP delivery and validation fields.
            $table->string('otp_hash', 255)->nullable();  // bcrypt hash of the OTP
            $table->timestamp('otp_sent_at')->nullable();
            $table->timestamp('otp_expires_at')->nullable();

            // Error detail on failure.
            $table->text('failure_reason')->nullable();

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorized_transactions');
    }
};
