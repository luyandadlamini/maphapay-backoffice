<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardSubscriptionPlanChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $oldPlanCode,
        public readonly string $newPlanCode,
        public readonly string $prorationAmount,
    ) {
    }
}
