<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mpp_payments', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string('challenge_id')->index();
            $table->string('rail', 32);
            $table->integer('amount_cents');
            $table->string('currency', 10);
            $table->string('status', 32)->default('pending')->index();
            $table->string('payer_identifier')->nullable();
            $table->string('settlement_reference')->nullable()->index();
            $table->string('endpoint_method', 10)->nullable();
            $table->string('endpoint_path')->nullable();
            $table->json('payment_payload')->nullable();
            $table->string('payload_hash', 64)->nullable()->unique();
            $table->text('error_message')->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpp_payments');
    }
};
