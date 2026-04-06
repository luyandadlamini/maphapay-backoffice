<?php

declare(strict_types=1);

/**
 * AML Screening Reviewed Event.
 */

namespace App\Domain\Compliance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Event fired when AML screening is reviewed.
 */
class AmlScreeningReviewed extends ShouldBeStored
{
    /**
     * Create new AML screening reviewed event.
     */
    public function __construct(
        public string $reviewedBy,
        public string $decision,
        public string $notes
    ) {
    }
}
