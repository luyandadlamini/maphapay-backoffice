<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('mpp_monetized_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('method', 10);
            $table->string('path');
            $table->integer('amount_cents');
            $table->string('currency', 10)->default('USD');
            $table->json('available_rails');
            $table->string('description')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['method', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpp_monetized_resources');
    }
};
