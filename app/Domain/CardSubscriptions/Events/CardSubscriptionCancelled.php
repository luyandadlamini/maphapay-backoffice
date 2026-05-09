<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardSubscriptionCancelled extends ShouldBeStored
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $cancelledAt,
        public readonly string $cancelledBy,
    ) {
    }
}
