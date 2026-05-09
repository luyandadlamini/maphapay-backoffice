<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MinorCardRequestDenied extends ShouldBeStored
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $minorAccountUuid,
        public readonly string $guardianUserId,
        public readonly string $denialReason,
    ) {
    }
}
