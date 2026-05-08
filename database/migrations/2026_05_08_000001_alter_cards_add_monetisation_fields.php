<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->string('tier', 16)->default('standard')->after('network');
            $table->string('kind', 16)->default('virtual')->after('tier');
            $table->string('lifecycle', 24)->default('standard')->after('kind');
            $table->json('lifecycle_config')->nullable()->after('lifecycle');
            $table->boolean('is_default')->default(false)->after('lifecycle_config');

            // Per-category limits replace the single spend_limit_cents
            $table->decimal('per_transaction_limit', 18, 2)->nullable()->after('is_default');
            $table->decimal('daily_limit', 18, 2)->nullable()->after('per_transaction_limit');
            $table->decimal('monthly_limit', 18, 2)->nullable()->after('daily_limit');
            $table->decimal('atm_daily_limit', 18, 2)->nullable()->after('monthly_limit');
            $table->decimal('atm_monthly_limit', 18, 2)->nullable()->after('atm_daily_limit');
            $table->decimal('contactless_per_transaction_limit', 18, 2)->nullable()->after('atm_monthly_limit');

            // Booleans for per-card toggles (overlay onto plan defaults)
            $table->boolean('online_enabled')->default(true)->after('contactless_per_transaction_limit');
            $table->boolean('international_enabled')->default(true)->after('online_enabled');
            $table->boolean('atm_enabled')->default(false)->after('international_enabled');
            $table->boolean('contactless_enabled')->default(true)->after('atm_enabled');

            // Blocked MCC group keys
            $table->json('blocked_mcc_groups')->nullable()->after('contactless_enabled');

            // Subscription this card belongs to
            $table->uuid('card_subscription_id')->nullable()->after('blocked_mcc_groups');

            $table->index(['user_id', 'is_default']);
            $table->index('card_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'is_default']);
            $table->dropIndex(['card_subscription_id']);
            $table->dropColumn([
                'tier', 'kind', 'lifecycle', 'lifecycle_config', 'is_default',
                'per_transaction_limit', 'daily_limit', 'monthly_limit',
                'atm_daily_limit', 'atm_monthly_limit', 'contactless_per_transaction_limit',
                'online_enabled', 'international_enabled', 'atm_enabled', 'contactless_enabled',
                'blocked_mcc_groups', 'card_subscription_id',
            ]);
        });
    }
};
