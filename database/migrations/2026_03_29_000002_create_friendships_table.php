<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('friend_id')->index();
            $table->foreign('friend_id')->references('id')->on('users')->cascadeOnDelete();

            // Canonical status: accepted | blocked
            $table->string('status', 32)->default('accepted')->index();

            // Migration provenance — null for organically created friendships.
            $table->string('migrated_from', 64)->nullable()->index();
            $table->timestamp('migrated_at')->nullable();

            $table->timestamps();

            // Each ordered pair is unique; (A,B) and (B,A) are both stored.
            $table->unique(['user_id', 'friend_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
