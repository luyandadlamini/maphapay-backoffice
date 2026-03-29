<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('migration_run_logs', function (Blueprint $table) {
            $table->id();
            $table->string('run_type', 64);
            $table->string('key', 191);
            $table->text('value');
            $table->timestamps();

            $table->unique(['run_type', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_run_logs');
    }
};
