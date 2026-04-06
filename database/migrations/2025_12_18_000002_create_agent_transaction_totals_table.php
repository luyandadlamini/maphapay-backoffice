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
        if (! Schema::hasTable('agent_transaction_totals')) {
            Schema::create('agent_transaction_totals', function (Blueprint $table) {
                $table->id();
                $table->string('agent_id')->index();
                $table->decimal('daily_total', 20, 2)->default(0);
                $table->decimal('weekly_total', 20, 2)->default(0);
                $table->decimal('monthly_total', 20, 2)->default(0);
                $table->dateTime('last_daily_reset')->nullable();
                $table->dateTime('last_weekly_reset')->nullable();
                $table->dateTime('last_monthly_reset')->nullable();
                $table->dateTime('last_transaction_at')->nullable();
                $table->timestamps();

                $table->foreign('agent_id')
                    ->references('agent_id')
                    ->on('agents')
                    ->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_transaction_totals');
    }
};
