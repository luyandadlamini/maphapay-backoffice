<?php

declare(strict_types=1);

namespace App\Domain\Batch\Events;

use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BatchJobCompleted extends ShouldBeStored
{
    public string $queue = EventQueues::TRANSACTIONS->value;

    /**
     * @param  string  $finalStatus  // completed, completed_with_errors, failed
     */
    public function __construct(
        public readonly string $completedAt,
        public readonly int $totalProcessed,
        public readonly int $totalFailed,
        public readonly string $finalStatus
    ) {
    }
}
