<?php

declare(strict_types=1);

namespace App\Domain\Analytics\DTO;

use Carbon\CarbonInterface;

/**
 * Cached bundle for revenue admin overview + streams (activity/volume, ADR-006).
 *
 * @phpstan-type StreamMetricsMap array<string, StreamActivityMetricsDto>
 */
final readonly class WalletRevenueActivityResult
{
    /**
     * @param  StreamMetricsMap  $streamMetrics  keyed by {@see \App\Domain\Analytics\WalletRevenueStream} value
     * @param  list<RevenueTargetAnomalyRowDto>  $anomalousTargets
     */
    public function __construct(
        public CarbonInterface $periodStart,
        public CarbonInterface $periodEnd,
        public OverviewActivityDto $overview,
        public array $streamMetrics,
        public array $anomalousTargets,
    ) {
    }
}
