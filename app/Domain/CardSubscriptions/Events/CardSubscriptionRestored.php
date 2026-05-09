<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardSubscriptionRestored extends ShouldBeStored
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $restoredAt,
    ) {
    }
}
