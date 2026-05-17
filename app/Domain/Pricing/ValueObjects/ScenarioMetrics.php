<?php

declare(strict_types=1);

namespace App\Domain\Pricing\ValueObjects;

/**
 * Immutable aggregate result of a pricing scenario simulation run.
 *
 * Each entry in $byCategory has the shape:
 *   [
 *     'product_code'        => string,
 *     'gross_revenue_minor' => int,
 *     'fee_count'           => int,
 *     'avg_fee_minor'       => int,
 *   ]
 */
final class ScenarioMetrics
{
    /**
     * @param array<string, array{product_code: string, gross_revenue_minor: int, fee_count: int, avg_fee_minor: int}> $byCategory
     */
    public function __construct(
        public readonly array $byCategory,
        public readonly int $totalGrossRevenueMinor,
        public readonly int $arpuMinor,
        public readonly float $grossMarginPct,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'by_category'               => $this->byCategory,
            'total_gross_revenue_minor' => $this->totalGrossRevenueMinor,
            'arpu_minor'                => $this->arpuMinor,
            'gross_margin_pct'          => $this->grossMarginPct,
        ];
    }
}
