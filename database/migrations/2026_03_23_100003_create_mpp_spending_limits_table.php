<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mpp_spending_limits', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_id')->index();
            $table->integer('daily_limit')->default(5000);
            $table->integer('per_tx_limit')->default(100);
            $table->integer('spent_today')->default(0);
            $table->boolean('auto_pay')->default(false);
            $table->date('last_reset')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpp_spending_limits');
    }
};
