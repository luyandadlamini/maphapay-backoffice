<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_budgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid');
            $table->decimal('monthly_budget', 15, 2)->default(0);
            $table->integer('month');
            $table->integer('year');
            $table->timestamps();

            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();

            $table->unique(['user_uuid', 'month', 'year']);
            $table->index('user_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_budgets');
    }
};
