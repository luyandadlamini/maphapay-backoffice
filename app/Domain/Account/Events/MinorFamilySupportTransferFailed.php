<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MinorFamilySupportTransferFailed extends ShouldBeStored
{
    public string $queue = EventQueues::TRANSFERS->value;

    public function __construct(
        public readonly string $familySupportTransferUuid,
        public readonly string $minorAccountUuid,
        public readonly string $reason,
    ) {
    }
}
