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
        Schema::create('customer_segments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('source', 20); // 'static'/'dynamic'/'hybrid'
            $table->json('rules')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_segments');
    }
};
