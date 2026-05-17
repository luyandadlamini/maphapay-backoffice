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
        Schema::dropIfExists('fee_events');

        Schema::create('fee_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('transaction_uuid', 36)->nullable();
            $table->unsignedBigInteger('pricing_rule_id')->nullable();
            $table->string('product_code', 50);
            $table->string('category', 50);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 10);
            $table->json('breakdown');
            $table->timestamp('assessed_at');
            $table->string('source_domain', 80)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->string('experiment_arm', 10)->nullable();
            $table->timestamps();

            $table->foreign('pricing_rule_id')->references('id')->on('pricing_rules')->nullOnDelete();
            $table->foreign('segment_id')->references('id')->on('customer_segments')->nullOnDelete();

            $table->index('transaction_uuid');
            $table->index('user_id');
            $table->index('assessed_at');
            $table->index(['product_code', 'assessed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_events');
    }
};
