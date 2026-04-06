<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class PoolRatioDeviationDetected extends ShouldBeStored
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $currentRatio,
        public readonly string $targetRatio,
        public readonly string $deviationPercentage,
        public readonly string $baseReserve,
        public readonly string $quoteReserve,
        public readonly array $metadata = []
    ) {
    }
}
