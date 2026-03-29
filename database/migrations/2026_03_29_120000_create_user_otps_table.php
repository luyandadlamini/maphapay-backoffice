<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_otps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index(); // mobile_verification | pin_reset | login
            $table->string('otp_hash', 255);
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            // Active OTP per user per type — only one active OTP at a time
            $table->unique(['user_id', 'type'], 'user_otps_user_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_otps');
    }
};
