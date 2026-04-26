<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('revenue_targets')) {
            return;
        }

        Schema::create('revenue_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('period_month', 7)->comment('YYYY-MM');
            $table->string('stream_code', 64);
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('ZAR');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['period_month', 'stream_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_targets');
    }
};
