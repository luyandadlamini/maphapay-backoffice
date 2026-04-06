<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class EmergencyPoolPaused extends ShouldBeStored
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $reason,
        public readonly string $pausedBy, // User ID or system
        public readonly string $pausedAt,
        public readonly array $poolState, // Snapshot of pool state at pause
        public readonly array $metadata = []
    ) {
    }
}
