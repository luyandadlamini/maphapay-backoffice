<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('budget_category_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid');
            $table->uuid('category_id');
            $table->uuid('transaction_uuid');
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date');
            $table->timestamps();

            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('category_id')
                ->references('uuid')
                ->on('budget_categories')
                ->cascadeOnDelete();

            $table->index('user_uuid');
            $table->index('category_id');
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_category_transactions');
    }
};
