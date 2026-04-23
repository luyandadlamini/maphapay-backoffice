<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MinorAccountLifecycleTransitionScheduled extends ShouldBeStored
{
    public function __construct(
        public readonly string $transitionId,
        public readonly string $minorAccountUuid,
        public readonly string $transitionType,
        public readonly string $effectiveAt,
        public readonly array $metadata = [],
    ) {
    }
}
