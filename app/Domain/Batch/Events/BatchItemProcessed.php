<?php

declare(strict_types=1);

namespace App\Domain\Batch\Events;

use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BatchItemProcessed extends ShouldBeStored
{
    public string $queue = EventQueues::TRANSACTIONS->value;

    /**
     * @param  string  $status  // completed, failed
     */
    public function __construct(
        public readonly int $itemIndex,
        public readonly string $status,
        public readonly array $result,
        public readonly ?string $errorMessage = null
    ) {
    }
}
