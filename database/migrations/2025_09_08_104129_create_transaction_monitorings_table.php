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
        Schema::create('transaction_monitorings', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->index();
            $table->string('status')->default('pending');
            $table->decimal('risk_score', 5, 2)->default(0);
            $table->enum('risk_level', ['minimal', 'low', 'medium', 'high', 'critical'])->default('low');
            $table->json('patterns')->nullable();
            $table->json('triggered_rules')->nullable();
            $table->text('flag_reason')->nullable();
            $table->text('clear_reason')->nullable();
            $table->dateTime('analyzed_at')->nullable();
            $table->dateTime('flagged_at')->nullable();
            $table->dateTime('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'risk_level']);
            $table->index('risk_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_monitorings');
    }
};
