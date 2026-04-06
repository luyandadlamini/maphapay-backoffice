<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('liquidity_providers', function (Blueprint $table) {
            $table->decimal('initial_base_amount', 36, 18)->default(0)->after('shares');
            $table->decimal('initial_quote_amount', 36, 18)->default(0)->after('initial_base_amount');
            $table->json('metadata')->nullable()->after('pending_rewards');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidity_providers', function (Blueprint $table) {
            $table->dropColumn(['initial_base_amount', 'initial_quote_amount', 'metadata']);
        });
    }
};
