<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class ImpermanentLossCalculated extends ShouldBeStored
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $providerId,
        public readonly string $initialValueUsd,
        public readonly string $currentValueUsd,
        public readonly string $holdValueUsd, // Value if just held assets
        public readonly string $impermanentLossUsd,
        public readonly string $impermanentLossPercentage,
        public readonly array $metadata = []
    ) {
    }
}
