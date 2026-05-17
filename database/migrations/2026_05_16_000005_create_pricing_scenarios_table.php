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
        Schema::create('pricing_scenarios', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('mode', 20)->default('deterministic');
            $table->char('created_by', 36)->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_run_result')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_scenarios');
    }
};
