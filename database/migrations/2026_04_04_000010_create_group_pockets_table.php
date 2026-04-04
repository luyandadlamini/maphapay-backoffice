<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('group_pockets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('threads')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('name', 150);
            $table->enum('category', [
                'travel', 'transport', 'tech', 'emergency',
                'food', 'health', 'education', 'general',
            ])->default('general');
            $table->string('color', 7)->default('#6366F1');
            $table->decimal('target_amount', 12, 2);
            $table->decimal('current_amount', 12, 2)->default(0);
            $table->date('target_date')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->enum('status', ['active', 'completed', 'closed'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_pockets');
    }
};
