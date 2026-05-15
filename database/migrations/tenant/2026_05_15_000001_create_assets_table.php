<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped `assets` table.
 *
 * The original `create_assets_table` migration lives in the central migrations folder
 * (`database/migrations/2025_06_15_183648_create_assets_table.php`) but the Asset
 * model uses the tenant connection (UsesTenantConnection). Every fresh tenant DB
 * was therefore missing this table, causing dashboard / transaction / pocket
 * endpoints to 500 with "Base table or view not found: tenant....assets" the
 * moment a user logged in. This migration fixes that for all future tenants.
 *
 * Seeded with the same defaults as the central migration plus SZL (the local
 * brand currency) so MaphaPay's home-screen balance lookup resolves cleanly.
 *
 * Idempotent (uses Schema::hasTable / insertOrIgnore-style guards) so re-running
 * against a tenant that was already manually backfilled is safe.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('assets')) {
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
        }

        $defaults = [
            ['code' => 'SZL', 'name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'metadata' => ['symbol' => 'E', 'iso_code' => 'SZL']],
            ['code' => 'USD', 'name' => 'US Dollar',       'type' => 'fiat', 'precision' => 2, 'metadata' => ['symbol' => '$', 'iso_code' => 'USD']],
            ['code' => 'EUR', 'name' => 'Euro',            'type' => 'fiat', 'precision' => 2, 'metadata' => ['symbol' => '€', 'iso_code' => 'EUR']],
            ['code' => 'GBP', 'name' => 'British Pound',   'type' => 'fiat', 'precision' => 2, 'metadata' => ['symbol' => '£', 'iso_code' => 'GBP']],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'type' => 'fiat', 'precision' => 2, 'metadata' => ['symbol' => 'R', 'iso_code' => 'ZAR']],
            ['code' => 'BTC', 'name' => 'Bitcoin',         'type' => 'crypto', 'precision' => 8, 'metadata' => ['symbol' => '₿', 'network' => 'bitcoin']],
            ['code' => 'ETH', 'name' => 'Ethereum',        'type' => 'crypto', 'precision' => 18, 'metadata' => ['symbol' => 'Ξ', 'network' => 'ethereum']],
        ];

        $now = now();
        foreach ($defaults as $row) {
            // insertOrIgnore on the primary key avoids duplicate-key errors if the table
            // was previously backfilled out-of-band.
            DB::table('assets')->insertOrIgnore([
                'code'       => $row['code'],
                'name'       => $row['name'],
                'type'       => $row['type'],
                'precision'  => $row['precision'],
                'is_active'  => true,
                'metadata'   => json_encode($row['metadata']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
