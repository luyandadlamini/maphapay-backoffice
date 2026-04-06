<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['text', 'bill_split', 'payment', 'request', 'system']);
            $table->text('text')->nullable();
            $table->json('payload')->nullable();
            $table->string('idempotency_key', 36)->nullable()->unique();
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
