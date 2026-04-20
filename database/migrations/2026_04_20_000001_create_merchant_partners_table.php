<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('category', 50)->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('qr_endpoint', 2048)->nullable();
            $table->string('api_key', 255)->nullable();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->enum('payout_schedule', ['weekly', 'monthly'])->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_partners');
    }
};
