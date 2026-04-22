<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('minor_rewards')) {
            return;
        }

        Schema::create('minor_rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('description');
            $table->unsignedInteger('points_cost');
            $table->string('type', 30); // 'airtime'|'data_bundle'|'voucher'|'charity_donation'
            $table->json('metadata')->nullable(); // e.g. {"amount":"50","provider":"MTN"}
            $table->integer('stock')->default(-1); // -1 = unlimited
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('min_permission_level')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minor_rewards');
    }
};
