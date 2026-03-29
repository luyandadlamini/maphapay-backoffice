<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 2)->unique(); // ISO 3166-1 alpha-2
            $table->string('dial_code', 8); // e.g. "+268"
            $table->string('currency_code', 3)->nullable(); // ISO 4217
            $table->string('currency_name', 50)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
