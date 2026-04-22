<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_reward_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('minor_account_uuid')->index();
            $table->uuid('minor_reward_id')->index();
            $table->unsignedInteger('points_cost'); // snapshot at redemption time
            $table->string('status', 20)->default('pending'); // 'pending'|'fulfilled'|'failed'
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_reward_redemptions');
    }
};
