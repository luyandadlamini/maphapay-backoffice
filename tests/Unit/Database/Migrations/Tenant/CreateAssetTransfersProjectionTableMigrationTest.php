<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Migrations\Tenant;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Large]
class CreateAssetTransfersProjectionTableMigrationTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function backfill_preserves_minor_units_from_metadata_when_available(): void
    {
        $migration = require base_path('database/migrations/tenant/2026_04_03_000001_create_asset_transfers_projection_table.php');

        $sourceRows = collect([
            (object) [
                'uuid' => 'transfer-uuid-1',
                'reference' => 'legacy-ref-1',
                'from_account_uuid' => 'from-account-1',
                'to_account_uuid' => 'to-account-1',
                'amount' => '10.50',
                'currency' => 'SZL',
                'exchange_rate' => '0.8500000000',
                'status' => 'completed',
                'description' => 'Legacy transfer',
                'metadata' => json_encode([
                    'transfer_id' => 'legacy-transfer-1',
                    'hash' => 'hash-1',
                    'from_asset_code' => 'SZL',
                    'to_asset_code' => 'USD',
                    'from_amount' => 1050,
                    'to_amount' => 850,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now()->subDay(),
                'updated_at' => now(),
                'completed_at' => now(),
            ],
        ]);

        Schema::shouldReceive('hasTable')->once()->with('asset_transfers')->andReturn(true);
        Schema::shouldReceive('hasTable')->once()->with('transfers')->andReturn(true);
        Schema::shouldReceive('hasColumn')->once()->with('transfers', 'uuid')->andReturn(true);
        Schema::shouldReceive('hasColumn')->once()->with('transfers', 'from_account_uuid')->andReturn(true);
        Schema::shouldReceive('hasColumn')->once()->with('transfers', 'to_account_uuid')->andReturn(true);

        $sourceBuilder = Mockery::mock(Builder::class);
        $targetBuilder = Mockery::mock(Builder::class);
        $assetsBuilder = Mockery::mock(Builder::class);

        DB::shouldReceive('table')->once()->with('transfers')->andReturn($sourceBuilder);
        $sourceBuilder->shouldReceive('get')->once()->andReturn($sourceRows);

        DB::shouldReceive('table')->twice()->with('assets')->andReturn($assetsBuilder);
        $assetsBuilder->shouldReceive('where')->twice()->with('code', Mockery::type('string'))->andReturnSelf();
        $assetsBuilder->shouldReceive('value')->twice()->with('precision')->andReturn(2);

        DB::shouldReceive('table')->once()->with('asset_transfers')->andReturn($targetBuilder);
        $targetBuilder->shouldReceive('updateOrInsert')
            ->once()
            ->with(
                ['uuid' => 'transfer-uuid-1'],
                Mockery::on(static function (array $payload): bool {
                    return $payload['transfer_id'] === 'legacy-transfer-1'
                        && $payload['from_asset_code'] === 'SZL'
                        && $payload['to_asset_code'] === 'USD'
                        && $payload['from_amount'] === 1050
                        && $payload['to_amount'] === 850;
                }),
            );

        $migration->up();

        $this->assertTrue(true);
    }
}
