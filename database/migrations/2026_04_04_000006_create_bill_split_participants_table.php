<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('bill_split_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_split_id')->constrained('bill_splits')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->unique(['bill_split_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_split_participants');
    }
};
