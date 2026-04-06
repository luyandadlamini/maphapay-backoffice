<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exchange\LiquidityPool\ValueObjects;

use App\Domain\Exchange\LiquidityPool\ValueObjects\PoolRatio;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PoolRatioTest extends TestCase
{
    #[Test]
    public function test_can_create_pool_ratio(): void
    {
        $ratio = new PoolRatio('100', '2000');

        $this->assertEquals('100', $ratio->getBaseReserve()->__toString());
        $this->assertEquals('2000', $ratio->getQuoteReserve()->__toString());
        $this->assertEquals('0.05', $ratio->getRatio()->toScale(2)->__toString());
        $this->assertEquals('20', $ratio->getPrice()->toScale(0)->__toString());
        $this->assertEquals('200000', $ratio->getK()->__toString());
    }

    #[Test]
    public function test_throws_exception_for_negative_reserves(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reserves cannot be negative');

        new PoolRatio('-100', '2000');
    }

    #[Test]
    public function test_throws_exception_for_zero_reserves(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reserves cannot be zero');

        new PoolRatio('100', '0');
    }

    #[Test]
    public function test_calculate_deviation(): void
    {
        $currentRatio = new PoolRatio('100', '2000'); // 0.05
        $targetRatio = new PoolRatio('100', '2100'); // 0.047619...

        $deviation = $currentRatio->calculateDeviation($targetRatio);

        // |0.05 - 0.047619| / 0.047619 ≈ 0.05
        $this->assertGreaterThan('0.04', $deviation->__toString());
        $this->assertLessThan('0.06', $deviation->__toString());
    }

    #[Test]
    public function test_is_deviation_within_tolerance(): void
    {
        $currentRatio = new PoolRatio('100', '2000');
        $targetRatio = new PoolRatio('100', '2050'); // Small deviation
        $largeDeviationRatio = new PoolRatio('100', '3000'); // Large deviation

        $this->assertTrue($currentRatio->isDeviationWithinTolerance($targetRatio, '0.05'));
        $this->assertFalse($currentRatio->isDeviationWithinTolerance($largeDeviationRatio, '0.05'));
    }

    #[Test]
    public function test_calculate_price_impact_base_input(): void
    {
        $ratio = new PoolRatio('100', '2000'); // Price = 20

        // Adding 10 base tokens
        $priceImpact = $ratio->calculatePriceImpact(BigDecimal::of('10'), true);

        // Price impact should be positive and reasonable
        $this->assertGreaterThan('0', $priceImpact->__toString());
        $this->assertLessThan('15', $priceImpact->__toString()); // Less than 15%
    }

    #[Test]
    public function test_calculate_price_impact_quote_input(): void
    {
        $ratio = new PoolRatio('100', '2000'); // Price = 20

        // Adding 200 quote tokens
        $priceImpact = $ratio->calculatePriceImpact(BigDecimal::of('200'), false);

        // Price impact should be positive and reasonable
        $this->assertGreaterThan('0', $priceImpact->__toString());
        $this->assertLessThan('15', $priceImpact->__toString()); // Less than 15%
    }

    #[Test]
    public function test_to_array(): void
    {
        $ratio = new PoolRatio('100', '2000');

        $array = $ratio->toArray();

        $this->assertEquals('100', $array['base_reserve']);
        $this->assertEquals('2000', $array['quote_reserve']);
        $this->assertEquals('0.050000', $array['ratio']);
        $this->assertEquals('20.00', $array['price']);
        $this->assertEquals('200000', $array['k']);
    }
}
