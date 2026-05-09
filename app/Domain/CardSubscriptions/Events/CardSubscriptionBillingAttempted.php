<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardSubscriptionBillingAttempted extends ShouldBeStored
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $billingAttemptId,
        public readonly string $result,
        public readonly string $amount,
        public readonly ?string $failureReason,
    ) {
    }
}
