<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_redemption_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('redemption_id')->unique();
            $table->unsignedBigInteger('parent_account_id');
            $table->enum('status', ['pending', 'approved', 'declined'])->default('pending');
            $table->string('reason', 255)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at'); // 24h timeout
            $table->timestamps();

            $table->foreign('redemption_id')->references('id')->on('minor_reward_redemptions')->onDelete('cascade');
            $table->foreign('parent_account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_redemption_approvals');
    }
};
