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
        Schema::dropIfExists('revenue_daily_rollups');

        Schema::create('revenue_daily_rollups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date');
            $table->string('product_code', 50);
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->string('currency', 10);
            $table->bigInteger('gross_revenue_minor')->default(0);
            $table->integer('fee_count')->default(0);
            $table->integer('unique_users')->default(0);
            $table->bigInteger('avg_fee_minor')->default(0);
            $table->timestamps();

            $table->foreign('segment_id')->references('id')->on('customer_segments')->nullOnDelete();
            $table->unique(['date', 'product_code', 'segment_id', 'currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revenue_daily_rollups');
    }
};
