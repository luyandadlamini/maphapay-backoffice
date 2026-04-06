<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_asset_code', 10);
            $table->string('to_asset_code', 10);
            $table->decimal('rate', 20, 10);
            $table->string('source', 50)->default('manual');
            $table->dateTime('valid_at');
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('from_asset_code')->references('code')->on('assets');
            $table->foreign('to_asset_code')->references('code')->on('assets');

            $table->index(['from_asset_code', 'to_asset_code']);
            $table->index(['valid_at', 'expires_at']);
            $table->index(['is_active', 'valid_at']);
            $table->index('source');

            $table->unique(['from_asset_code', 'to_asset_code', 'valid_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
