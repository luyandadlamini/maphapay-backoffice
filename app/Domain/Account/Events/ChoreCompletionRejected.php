<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ChoreCompletionRejected extends ShouldBeStored
{
    public function __construct(
        public readonly string $choreId,
        public readonly string $completionId,
        public readonly string $minorAccountUuid,
        public readonly string $reviewedByAccountUuid,
        public readonly string $reason,
    ) {
    }
}
