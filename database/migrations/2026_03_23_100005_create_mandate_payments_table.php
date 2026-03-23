<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mandate_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('mandate_id');
            $table->string('payment_type');
            $table->string('payment_id');
            $table->integer('amount_cents');
            $table->string('currency', 10);
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->foreign('mandate_id')->references('uuid')->on('agent_mandates')->cascadeOnDelete();
            $table->index(['payment_type', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandate_payments');
    }
};
