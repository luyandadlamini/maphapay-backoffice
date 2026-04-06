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
        Schema::create('turnovers', function (Blueprint $table) {
            $table->id();
            $table->uuid('account_uuid');
            $table->date('date');
            $table->integer('count')->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['account_uuid', 'date'], 'account_date');
            $table->foreign('account_uuid', 'turnovers_account')->references('uuid')->on('accounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('turnovers');
    }
};
