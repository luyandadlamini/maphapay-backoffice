<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PointsDeducted extends ShouldBeStored
{
    public function __construct(
        public readonly string $minorAccountUuid,
        public readonly int $points,
        public readonly string $source,
        public readonly string $description,
        public readonly ?string $referenceId,
        public readonly ?string $actorUserUuid = null,
    ) {
    }
}
