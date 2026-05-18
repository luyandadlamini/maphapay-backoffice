<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class CardsTableHasMonetisationColumnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }

        if (! Schema::hasTable('cards')) {
            $this->markTestSkipped('cards table does not exist — run migrations first.');
        }
    }

    /** @return array<string, array{string}> */
    public static function monetisationColumns(): array
    {
        return [
            'tier'                              => ['tier'],
            'kind'                              => ['kind'],
            'lifecycle'                         => ['lifecycle'],
            'lifecycle_config'                  => ['lifecycle_config'],
            'is_default'                        => ['is_default'],
            'per_transaction_limit'             => ['per_transaction_limit'],
            'daily_limit'                       => ['daily_limit'],
            'monthly_limit'                     => ['monthly_limit'],
            'atm_daily_limit'                   => ['atm_daily_limit'],
            'atm_monthly_limit'                 => ['atm_monthly_limit'],
            'contactless_per_transaction_limit' => ['contactless_per_transaction_limit'],
            'online_enabled'                    => ['online_enabled'],
            'international_enabled'             => ['international_enabled'],
            'atm_enabled'                       => ['atm_enabled'],
            'contactless_enabled'               => ['contactless_enabled'],
            'blocked_mcc_groups'                => ['blocked_mcc_groups'],
            'card_subscription_id'              => ['card_subscription_id'],
        ];
    }

    #[Test]
    #[DataProvider('monetisationColumns')]
    public function it_has_monetisation_column(string $column): void
    {
        $this->assertTrue(
            Schema::hasColumn('cards', $column),
            "cards table is missing column: {$column}",
        );
    }

    #[Test]
    public function it_has_all_17_monetisation_columns(): void
    {
        $columns = array_keys(self::monetisationColumns());
        $missing = array_filter($columns, fn (string $col) => ! Schema::hasColumn('cards', $col));

        $this->assertEmpty(
            $missing,
            'cards table is missing these columns: ' . implode(', ', $missing),
        );
    }
}
