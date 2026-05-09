<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardDisputeResolved extends ShouldBeStored
{
    public function __construct(
        public readonly string $disputeId,
        public readonly string $finalStatus,
        public readonly string $resolutionNotes,
    ) {
    }
}
