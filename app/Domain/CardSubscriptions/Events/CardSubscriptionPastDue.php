<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardSubscriptionPastDue extends ShouldBeStored
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly int $failedPaymentCount,
        public readonly string $gracePeriodEndsAt,
        public readonly string $failureReason,
    ) {
    }
}
