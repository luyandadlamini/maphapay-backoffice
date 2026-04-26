<?php

declare(strict_types=1);

namespace App\Domain\Analytics\DTO;

/**
 * Aggregate projection activity for the overview KPI row (mapped subset only: transfer + withdrawal).
 */
final readonly class OverviewActivityDto
{
    /**
     * @param  array<string, int>  $volumesByAsset  asset_code => sum of minor units
     */
    public function __construct(
        public int $transactionCount,
        public array $volumesByAsset,
        public ?string $lastActivityAtIso = null,
    ) {
    }
}
