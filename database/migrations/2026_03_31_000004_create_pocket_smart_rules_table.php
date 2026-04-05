<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('pocket_smart_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('pocket_id');
            $table->boolean('round_up_change')->default(false);
            $table->boolean('auto_save_deposits')->default(false);
            $table->boolean('auto_save_salary')->default(false);
            $table->decimal('auto_save_amount', 15, 2)->default(0);
            $table->string('auto_save_frequency', 20)->default('monthly');
            $table->boolean('lock_pocket')->default(false);
            $table->boolean('notify_on_transfer')->default(true);
            $table->timestamps();

            $table->foreign('pocket_id')
                ->references('uuid')
                ->on('pockets')
                ->cascadeOnDelete();

            $table->index('pocket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pocket_smart_rules');
    }
};
