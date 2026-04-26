<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('minor_card_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('minor_account_uuid')->unique();
            $table->decimal('daily_limit', 12, 2)->default(2000.00);
            $table->decimal('monthly_limit', 12, 2)->default(10000.00);
            $table->decimal('single_transaction_limit', 12, 2)->default(1500.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('minor_account_uuid')
                ->references('uuid')
                ->on('accounts')
                ->onDelete('cascade');
            $table->index(['tenant_id', 'minor_account_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_card_limits');
    }
};
