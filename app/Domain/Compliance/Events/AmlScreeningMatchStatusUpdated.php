<?php

declare(strict_types=1);

/**
 * AML Screening Match Status Updated Event.
 */

namespace App\Domain\Compliance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Event fired when AML screening match status is updated.
 */
class AmlScreeningMatchStatusUpdated extends ShouldBeStored
{
    /**
     * Create new AML screening match status updated event.
     */
    public function __construct(
        public string $matchId,
        public string $action,
        public array $details,
        public ?string $reason = null
    ) {
    }
}
