<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('friend_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sender_id')->index();
            $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('recipient_id')->index();
            $table->foreign('recipient_id')->references('id')->on('users')->cascadeOnDelete();

            // pending | accepted | rejected | cancelled
            $table->string('status', 32)->default('pending')->index();

            // Migration provenance — null for organically created requests.
            $table->string('migrated_from', 64)->nullable()->index();
            $table->timestamp('migrated_at')->nullable();

            $table->timestamps();

            // At most one open request between any ordered pair at a time.
            $table->unique(['sender_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friend_requests');
    }
};
