<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('money_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->unsignedBigInteger('requester_user_id')->index();
            $table->foreign('requester_user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unsignedBigInteger('recipient_user_id')->index();
            $table->foreign('recipient_user_id')->references('id')->on('users')->cascadeOnDelete();

            // Major-unit amount string (e.g. "25.10"), never integer minor units in API payloads.
            $table->string('amount', 64);
            $table->string('asset_code', 16)->index();
            $table->text('note')->nullable();

            $table->string('status', 32)->index();

            // Matches authorized_transactions.trx once the flow is initiated.
            $table->string('trx', 32)->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_requests');
    }
};
