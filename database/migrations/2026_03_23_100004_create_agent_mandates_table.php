<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('agent_mandates', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->string('type', 32)->index();
            $table->string('status', 32)->default('draft')->index();
            $table->string('issuer_did')->index();
            $table->string('subject_did')->index();
            $table->json('payload');
            $table->string('vdc_hash', 64)->nullable();
            $table->json('payment_references')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('amount_cents')->nullable();
            $table->string('currency', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_mandates');
    }
};
