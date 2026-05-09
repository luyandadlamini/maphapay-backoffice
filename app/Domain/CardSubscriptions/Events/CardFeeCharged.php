<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CardFeeCharged extends ShouldBeStored
{
    public function __construct(
        public readonly string $feeId,
        public readonly string $userId,
        public readonly string $feeType,
        public readonly string $amount,
        public readonly ?string $relatedEntityType,
        public readonly ?string $relatedEntityId,
    ) {
    }
}
