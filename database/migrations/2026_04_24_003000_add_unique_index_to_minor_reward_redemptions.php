<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('minor_reward_redemptions')) {
            return;
        }

        Schema::table('minor_reward_redemptions', function (Blueprint $table): void {
            $table->unique(['minor_account_id', 'reward_id', 'status', 'created_at'], 'minor_redemption_unique_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('minor_reward_redemptions')) {
            return;
        }

        Schema::table('minor_reward_redemptions', function (Blueprint $table): void {
            $table->dropUnique('minor_redemption_unique_idx');
        });
    }
};