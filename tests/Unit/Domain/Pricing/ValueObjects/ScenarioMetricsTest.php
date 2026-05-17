<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing\ValueObjects;

use App\Domain\Pricing\ValueObjects\ScenarioMetrics;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScenarioMetricsTest extends TestCase
{
    #[Test]
    public function it_holds_per_category_breakdown_and_totals(): void
    {
        $metrics = new ScenarioMetrics(
            byCategory: [
                'local_transfer' => [
                    'product_code'        => 'local_transfer_sim',
                    'gross_revenue_minor' => 20_000,
                    'fee_count'           => 100,
                    'avg_fee_minor'       => 200,
                ],
            ],
            totalGrossRevenueMinor: 20_000,
            arpuMinor: 400,
            grossMarginPct: 0.0,
        );

        $this->assertSame(20_000, $metrics->totalGrossRevenueMinor);
        $this->assertSame(400, $metrics->arpuMinor);
        $this->assertSame(0.0, $metrics->grossMarginPct);
        $this->assertSame(100, $metrics->byCategory['local_transfer']['fee_count']);
    }

    #[Test]
    public function it_serializes_to_array_for_persistence(): void
    {
        $metrics = new ScenarioMetrics(
            byCategory: [
                'local_transfer' => [
                    'product_code'        => 'local_transfer_sim',
                    'gross_revenue_minor' => 500,
                    'fee_count'           => 5,
                    'avg_fee_minor'       => 100,
                ],
            ],
            totalGrossRevenueMinor: 500,
            arpuMinor: 100,
            grossMarginPct: 0.0,
        );

        $array = $metrics->toArray();

        $this->assertArrayHasKey('by_category', $array);
        $this->assertSame(500, $array['total_gross_revenue_minor']);
        $this->assertSame(100, $array['arpu_minor']);
        $this->assertSame(0.0, $array['gross_margin_pct']);
    }
}
