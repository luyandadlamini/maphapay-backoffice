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
        Schema::create('pricing_scenario_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('scenario_id');
            $table->unsignedBigInteger('pricing_rule_id')->nullable();
            $table->json('config_override')->nullable();
            $table->timestamps();

            $table->foreign('scenario_id')->references('id')->on('pricing_scenarios')->onDelete('cascade');
            $table->foreign('pricing_rule_id')->references('id')->on('pricing_rules')->onDelete('setNull');
            $table->unique(['scenario_id', 'pricing_rule_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_scenario_rules');
    }
};
