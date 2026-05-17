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
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->string('name', 150);
            $table->string('formula', 30);
            $table->json('config');
            $table->json('geo_scope')->nullable();
            $table->string('channel', 50)->nullable();
            $table->integer('priority')->default(0);
            $table->string('status', 30)->default('draft');
            $table->integer('version')->default(1);
            $table->unsignedBigInteger('parent_rule_id')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->char('approved_by', 36)->nullable();
            $table->json('experiment_split')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('pricing_products')->onDelete('cascade');
            $table->foreign('segment_id')->references('id')->on('customer_segments')->onDelete('setNull');
            $table->foreign('parent_rule_id')->references('id')->on('pricing_rules')->onDelete('setNull');

            $table->index(['status', 'effective_from', 'effective_to']);
            $table->index(['product_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
