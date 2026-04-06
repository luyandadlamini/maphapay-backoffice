<?php

declare(strict_types=1);

namespace App\Domain\Stablecoin\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CustodianRemoved extends ShouldBeStored
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $custodianId,
        public readonly string $reason
    ) {
    }
}
