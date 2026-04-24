<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class RedemptionApproved extends ShouldBeStored
{
    public function __construct(
        public readonly string $redemptionId,
        public readonly string $minorAccountUuid,
        public readonly string $guardianAccountUuid,
        public readonly int $pointsCost,
    ) {
    }
}