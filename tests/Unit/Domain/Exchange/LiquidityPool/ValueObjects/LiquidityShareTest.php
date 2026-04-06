<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exchange\LiquidityPool\ValueObjects;

use App\Domain\Exchange\LiquidityPool\ValueObjects\LiquidityShare;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LiquidityShareTest extends TestCase
{
    #[Test]
    public function test_can_create_liquidity_share(): void
    {
        $share = new LiquidityShare('1000', '10000');

        $this->assertEquals('1000', $share->getAmount()->__toString());
        $this->assertEquals('10.00', $share->getPercentage()->toScale(2)->__toString());
    }

    #[Test]
    public function test_handles_zero_total_shares(): void
    {
        $share = new LiquidityShare('1000', '0');

        $this->assertEquals('1000', $share->getAmount()->__toString());
        $this->assertEquals('0', $share->getPercentage()->__toString());
    }

    #[Test]
    public function test_throws_exception_for_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Liquidity share amount cannot be negative');

        new LiquidityShare('-100', '1000');
    }

    #[Test]
    public function test_can_create_zero_share(): void
    {
        $share = LiquidityShare::zero();

        $this->assertTrue($share->isZero());
        $this->assertEquals('0', $share->getAmount()->__toString());
    }

    #[Test]
    public function test_can_add_shares(): void
    {
        $share1 = new LiquidityShare('1000', '10000');
        $share2 = new LiquidityShare('500', '10000');

        $result = $share1->add($share2);

        $this->assertEquals('1500', $result->getAmount()->__toString());
    }

    #[Test]
    public function test_can_subtract_shares(): void
    {
        $share1 = new LiquidityShare('1000', '10000');
        $share2 = new LiquidityShare('300', '10000');

        $result = $share1->subtract($share2);

        $this->assertEquals('700', $result->getAmount()->__toString());
    }

    #[Test]
    public function test_throws_exception_when_subtracting_more_than_available(): void
    {
        $share1 = new LiquidityShare('500', '10000');
        $share2 = new LiquidityShare('600', '10000');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot subtract more shares than available');

        $share1->subtract($share2);
    }

    #[Test]
    public function test_calculate_proportional_amount(): void
    {
        $share = new LiquidityShare('2500', '10000'); // 25% share
        $totalAmount = BigDecimal::of('1000');

        $proportional = $share->calculateProportionalAmount($totalAmount);

        $this->assertEquals('250', $proportional->toScale(0)->__toString());
    }

    #[Test]
    public function test_comparison_methods(): void
    {
        $share1 = new LiquidityShare('1000', '10000');
        $share2 = new LiquidityShare('500', '10000');
        $share3 = new LiquidityShare('1500', '10000');

        $this->assertTrue($share1->isGreaterThan($share2));
        $this->assertFalse($share1->isGreaterThan($share3));
        $this->assertTrue($share1->isLessThan($share3));
        $this->assertFalse($share1->isLessThan($share2));
    }

    #[Test]
    public function test_to_array(): void
    {
        $share = new LiquidityShare('2500', '10000');

        $array = $share->toArray();

        $this->assertEquals('2500', $array['amount']);
        $this->assertEquals('25.00%', $array['percentage']);
    }
}
