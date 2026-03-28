<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('scheduled_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('sender_user_id')->index();
            $table->foreign('sender_user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('recipient_user_id')->index();
            $table->foreign('recipient_user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('amount', 64);
            $table->string('asset_code', 16)->index();
            $table->text('note')->nullable();

            $table->dateTime('scheduled_for')->index();

            $table->string('status', 32)->index();

            $table->string('trx', 32)->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_sends');
    }
};
