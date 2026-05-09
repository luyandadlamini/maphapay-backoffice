<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardRiskEventOpened extends ShouldBeStored
{
    public function __construct(
        public readonly string $riskEventId,
        public readonly string $userId,
        public readonly ?string $cardId,
        public readonly string $eventType,
        public readonly string $severity,
    ) {
    }
}
