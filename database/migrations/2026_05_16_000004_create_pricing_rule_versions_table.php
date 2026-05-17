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
        Schema::create('pricing_rule_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pricing_rule_id');
            $table->integer('version');
            $table->json('config_snapshot');
            $table->string('status_before', 30);
            $table->string('status_after', 30);
            $table->char('changed_by', 36)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('pricing_rule_id')->references('id')->on('pricing_rules')->onDelete('cascade');
            $table->unique(['pricing_rule_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rule_versions');
    }
};
