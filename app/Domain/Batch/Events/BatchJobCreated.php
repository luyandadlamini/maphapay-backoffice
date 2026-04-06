<?php

declare(strict_types=1);

namespace App\Domain\Batch\Events;

use App\Domain\Batch\DataObjects\BatchJob;
use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BatchJobCreated extends ShouldBeStored
{
    public string $queue = EventQueues::TRANSACTIONS->value;

    public function __construct(
        public readonly BatchJob $batchJob
    ) {
    }
}
