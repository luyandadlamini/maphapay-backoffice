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
        // Create basket_assets table
        Schema::create('basket_assets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->enum('type', ['fixed', 'dynamic'])->default('fixed');
            $table->enum('rebalance_frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'never'])->default('never');
            $table->dateTime('last_rebalanced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 36)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('code');
            $table->index('is_active');
            $table->index('type');
        });

        // Create basket_components table
        Schema::create('basket_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('basket_asset_id')->constrained('basket_assets')->onDelete('cascade');
            $table->string('asset_code', 10);
            $table->decimal('weight', 5, 2); // Percentage 0.00-100.00
            $table->decimal('min_weight', 5, 2)->nullable();
            $table->decimal('max_weight', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('asset_code')->references('code')->on('assets');
            $table->unique(['basket_asset_id', 'asset_code']);
            $table->index('basket_asset_id');
        });

        // Create basket_values table
        Schema::create('basket_values', function (Blueprint $table) {
            $table->id();
            $table->string('basket_asset_code', 20);
            $table->decimal('value', 20, 8);
            $table->dateTime('calculated_at');
            $table->json('component_values')->nullable();
            $table->timestamps();

            $table->foreign('basket_asset_code')->references('code')->on('basket_assets')->onDelete('cascade');
            $table->index(['basket_asset_code', 'calculated_at']);
        });

        // Add basket_assets to the assets table as a new type
        // This allows basket assets to be treated as regular assets
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('is_basket')->default(false)->after('is_active');
            $table->index('is_basket');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['is_basket']);
            $table->dropColumn('is_basket');
        });

        Schema::dropIfExists('basket_values');
        Schema::dropIfExists('basket_components');
        Schema::dropIfExists('basket_assets');
    }
};
