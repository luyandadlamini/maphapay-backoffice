<?php

declare(strict_types=1);

namespace App\Domain\Batch\Events;

use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BatchJobStarted extends ShouldBeStored
{
    public string $queue = EventQueues::TRANSACTIONS->value;

    public function __construct(
        public readonly string $startedAt
    ) {
    }
}
