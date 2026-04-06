<?php

declare(strict_types=1);

/**
 * AML Screening Completed Event.
 */

namespace App\Domain\Compliance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Event fired when AML screening is completed.
 */
class AmlScreeningCompleted extends ShouldBeStored
{
    /**
     * Create new AML screening completed event.
     */
    public function __construct(
        public string $finalStatus,
        public ?float $processingTime = null
    ) {
    }
}
