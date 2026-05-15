<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('wallet_provider_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('provider_id', 64);
            $table->string('provider_request_id', 128);
            $table->string('type', 32);
            $table->string('status', 32);
            $table->string('currency', 8);
            $table->unsignedBigInteger('amount_minor');
            $table->uuid('user_uuid')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'provider_request_id']);
            $table->index(['provider_id', 'status']);
            $table->index('user_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_provider_transactions');
    }
};
