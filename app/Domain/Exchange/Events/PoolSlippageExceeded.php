<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class PoolSlippageExceeded extends ShouldBeStored
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $transactionType, // 'swap', 'add_liquidity', 'remove_liquidity'
        public readonly string $expectedAmount,
        public readonly string $actualAmount,
        public readonly string $slippagePercentage,
        public readonly string $maxSlippageTolerance,
        public readonly array $metadata = []
    ) {
    }
}
