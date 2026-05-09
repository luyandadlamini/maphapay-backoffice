<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardRiskEventResolved extends ShouldBeStored
{
    public function __construct(
        public readonly string $riskEventId,
        public readonly string $adminId,
        public readonly string $resolutionNotes,
    ) {
    }
}
