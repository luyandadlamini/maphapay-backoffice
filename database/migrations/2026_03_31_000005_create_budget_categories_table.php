<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('budget_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid');
            $table->string('name', 100);
            $table->string('slug', 50);
            $table->string('icon', 50)->nullable();
            $table->decimal('budget_amount', 15, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();

            $table->index('user_uuid');
            $table->index('slug');
            $table->unique(['user_uuid', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_categories');
    }
};
