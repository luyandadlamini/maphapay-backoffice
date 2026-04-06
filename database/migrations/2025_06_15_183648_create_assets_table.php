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
        Schema::create('assets', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 100);
            $table->enum('type', ['fiat', 'crypto', 'commodity', 'custom']);
            $table->unsignedTinyInteger('precision')->default(2);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });

        // Insert default assets
        DB::table('assets')->insert([
            [
                'code'       => 'USD',
                'name'       => 'US Dollar',
                'type'       => 'fiat',
                'precision'  => 2,
                'is_active'  => true,
                'metadata'   => json_encode(['symbol' => '$', 'iso_code' => 'USD']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code'       => 'EUR',
                'name'       => 'Euro',
                'type'       => 'fiat',
                'precision'  => 2,
                'is_active'  => true,
                'metadata'   => json_encode(['symbol' => '€', 'iso_code' => 'EUR']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code'       => 'GBP',
                'name'       => 'British Pound',
                'type'       => 'fiat',
                'precision'  => 2,
                'is_active'  => true,
                'metadata'   => json_encode(['symbol' => '£', 'iso_code' => 'GBP']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code'       => 'BTC',
                'name'       => 'Bitcoin',
                'type'       => 'crypto',
                'precision'  => 8,
                'is_active'  => true,
                'metadata'   => json_encode(['symbol' => '₿', 'network' => 'bitcoin']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code'       => 'ETH',
                'name'       => 'Ethereum',
                'type'       => 'crypto',
                'precision'  => 18,
                'is_active'  => true,
                'metadata'   => json_encode(['symbol' => 'Ξ', 'network' => 'ethereum']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code'       => 'XAU',
                'name'       => 'Gold (Troy Ounce)',
                'type'       => 'commodity',
                'precision'  => 3,
                'is_active'  => true,
                'metadata'   => json_encode(['symbol' => 'Au', 'unit' => 'troy_ounce']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
