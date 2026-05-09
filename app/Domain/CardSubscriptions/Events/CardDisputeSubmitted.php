<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardDisputeSubmitted extends ShouldBeStored
{
    public function __construct(
        public readonly string $disputeId,
        public readonly string $userId,
        public readonly string $cardTransactionId,
        public readonly string $reason,
        public readonly string $disputedAmount,
    ) {
    }
}
