<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Models;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\Asset;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class TransactionProjectionFormattedAmountTest extends DomainTestCase
{
    #[Test]
    public function it_formats_amount_using_asset_precision(): void
    {
        Asset::factory()->fiat()->create([
            'code'      => 'SZL',
            'name'      => 'Swazi Lilangeni',
            'precision' => 2,
            'metadata'  => ['symbol' => 'E'],
        ]);

        $tx = TransactionProjection::factory()->create([
            'asset_code' => 'SZL',
            'amount'     => 2510,
        ]);

        $this->assertSame('25.10', $tx->formatted_amount);
    }

    #[Test]
    public function it_formats_crypto_amounts_using_eight_decimal_precision(): void
    {
        Asset::factory()->crypto()->create([
            'code'      => 'TBTC',
            'precision' => 8,
        ]);

        $tx = TransactionProjection::factory()->create([
            'asset_code' => 'TBTC',
            'amount'     => 100_000_000,
        ]);

        $this->assertSame('1.00000000', $tx->formatted_amount);
    }

    #[Test]
    public function it_falls_back_to_legacy_cents_divisor_when_asset_is_unknown(): void
    {
        $tx = TransactionProjection::factory()->create([
            'asset_code' => 'UNKNOWN',
            'amount'     => 10050,
        ]);

        $this->assertSame('100.50', $tx->formatted_amount);
    }
}
