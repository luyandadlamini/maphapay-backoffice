<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('group_pocket_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_pocket_id')->constrained('group_pockets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['group_pocket_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_pocket_contributions');
    }
};
