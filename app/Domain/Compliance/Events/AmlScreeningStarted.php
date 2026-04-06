<?php

declare(strict_types=1);

/**
 * AML Screening Started Event.
 */

namespace App\Domain\Compliance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Event fired when AML screening is started.
 */
class AmlScreeningStarted extends ShouldBeStored
{
    /**
     * Create new AML screening started event.
     */
    public function __construct(
        public string $entityId,
        public string $entityType,
        public string $screeningNumber,
        public string $type,
        public string $provider,
        public array $searchParameters,
        public ?string $providerReference = null
    ) {
    }
}
