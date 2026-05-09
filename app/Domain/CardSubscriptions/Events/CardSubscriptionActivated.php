<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardSubscriptionActivated extends ShouldBeStored
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $subscriberUserId,
        public readonly string $payerUserId,
        public readonly string $planCode,
        public readonly string $billedAmount,
        public readonly string $currentPeriodStart,
        public readonly string $currentPeriodEnd,
        public readonly bool $isMinorSubscription,
        public readonly ?string $guardianUserId,
    ) {
    }
}
