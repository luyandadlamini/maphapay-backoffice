<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mtn_callback_log', function (Blueprint $table) {
            $table->id();
            $table->string('mtn_reference_id', 100);
            $table->string('terminal_status', 32);
            $table->string('body_sha256', 64)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            // Prevent processing the same terminal-state transition twice (replay protection).
            $table->unique(['mtn_reference_id', 'terminal_status']);
            $table->index('mtn_reference_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtn_callback_log');
    }
};
