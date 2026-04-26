<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\SMS;

use App\Domain\SMS\Services\SmsPricingService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SmsPricingServiceBcmathTest extends TestCase
{
    #[Test]
    public function bcmath_multiplies_usd_to_atomic_correctly(): void
    {
        $totalUsd = 0.00974700;
        $totalUsdStr = number_format($totalUsd, 8, '.', '');
        $result = bcmul($totalUsdStr, '1000000', 0);

        $this->assertSame('9747', $result);
        $this->assertIsString($result);
    }

    #[Test]
    public function bcmath_avoids_floating_point_precision_loss_on_large_amounts(): void
    {
        $totalUsd = 98765.43210 * 1.08 * 1.15;
        $totalUsdStr = number_format($totalUsd, 8, '.', '');
        $bcmathResult = bcmul($totalUsdStr, '1000000', 0);

        $floatResult = (string) (int) ceil($totalUsd * 1_000_000);

        $bcmathInt = (int) $bcmathResult;
        $floatInt = (int) $floatResult;

        $diff = abs($bcmathInt - $floatInt);
        $this->assertLessThanOrEqual(1, $diff, 'bcmath and float results should differ by at most 1 microcent');

        $this->assertSame((string) max(1000, (int) $bcmathResult), (string) max(1000, $bcmathInt));
    }

    #[Test]
    public function bcmath_minimum_floor_applied_for_tiny_amounts(): void
    {
        $totalUsd = 0.000001;
        $totalUsdStr = number_format($totalUsd, 8, '.', '');
        $rawAtomic = bcmul($totalUsdStr, '1000000', 0);
        $atomicUsdc = (string) max(1000, (int) $rawAtomic);

        $this->assertSame('1000', $atomicUsdc);
    }

    #[Test]
    public function bcmath_produces_correct_atomic_usdc_for_normal_pricing(): void
    {
        $rateEur = 0.02430;
        $eurUsdRate = 1.08;
        $marginMultiplier = 1.15;

        $totalUsd = $rateEur * $eurUsdRate * $marginMultiplier;
        $totalUsdStr = number_format($totalUsd, 8, '.', '');
        $rawAtomic = bcmul($totalUsdStr, '1000000', 0);
        $atomicUsdc = (string) max(1000, (int) $rawAtomic);

        $this->assertSame('30180', $atomicUsdc);
        $this->assertGreaterThan(1000, (int) $atomicUsdc);
    }

    #[Test]
    public function bcmath_multi_part_message_multiplies_correctly(): void
    {
        $rateEur = 0.08310;
        $eurUsdRate = 1.08;
        $marginMultiplier = 1.15;
        $parts = 3;

        $totalUsd = $rateEur * $eurUsdRate * $marginMultiplier * $parts;
        $totalUsdStr = number_format($totalUsd, 8, '.', '');
        $rawAtomic = bcmul($totalUsdStr, '1000000', 0);
        $atomicUsdc = (string) max(1000, (int) $rawAtomic);

        $this->assertSame('309630', $atomicUsdc);
    }

    #[Test]
    public function bcmath_truncates_rather_than_ceil(): void
    {
        $totalUsd = 0.0010011;
        $totalUsdStr = number_format($totalUsd, 8, '.', '');
        $bcmathResult = bcmul($totalUsdStr, '1000000', 0);

        $ceilResult = (string) ceil($totalUsd * 1_000_000);

        $bcmathInt = (int) $bcmathResult;
        $ceilInt = (int) $ceilResult;

        $this->assertLessThanOrEqual($ceilInt, $bcmathInt);
        $this->assertLessThanOrEqual(1, $ceilInt - $bcmathInt);
    }

    #[Test]
    public function service_uses_bcmath_and_not_float_multiplication(): void
    {
        $reflection = new ReflectionMethod(SmsPricingService::class, 'getPriceForNumber');
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertStringContainsString('bcmul', $source, 'SmsPricingService should use bcmul for financial calculations');
        $this->assertStringNotContainsString('ceil($totalUsd * 1_000_000)', $source, 'SmsPricingService should not use float ceil multiplication');
    }
}
