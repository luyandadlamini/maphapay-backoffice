<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MinorFamilySupportTransferRefunded extends ShouldBeStored
{
    public string $queue = EventQueues::TRANSFERS->value;

    public function __construct(
        public readonly string $familySupportTransferUuid,
        public readonly string $minorAccountUuid,
        public readonly string $refundedToAccountUuid,
        public readonly string $amount,
        public readonly string $assetCode,
    ) {
    }
}
