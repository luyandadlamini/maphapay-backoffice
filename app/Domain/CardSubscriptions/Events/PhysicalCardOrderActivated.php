<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class PhysicalCardOrderActivated extends ShouldBeStored
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $cardId,
    ) {
    }
}
