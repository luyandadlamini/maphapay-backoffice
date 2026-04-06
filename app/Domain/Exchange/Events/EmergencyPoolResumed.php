<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

final class EmergencyPoolResumed extends ShouldBeStored
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $resumedBy,
        public readonly string $resumedAt,
        public readonly string $pauseDuration, // In seconds
        public readonly array $metadata = []
    ) {
    }
}
