<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('pockets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid');
            $table->string('name', 100);
            $table->decimal('target_amount', 15, 2)->default(0);
            $table->decimal('current_amount', 15, 2)->default(0);
            $table->date('target_date')->nullable();
            $table->string('category', 50)->default('general');
            $table->string('color', 20)->default('#4F8CFF');
            $table->boolean('is_completed')->default(false);
            $table->timestamps();

            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();

            $table->index('user_uuid');
            $table->index('category');
            $table->index('is_completed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pockets');
    }
};
