<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MinorAccountLifecycleTransitionBlocked extends ShouldBeStored
{
    public function __construct(
        public readonly string $transitionId,
        public readonly string $minorAccountUuid,
        public readonly string $reasonCode,
        public readonly array $metadata = [],
    ) {
    }
}
