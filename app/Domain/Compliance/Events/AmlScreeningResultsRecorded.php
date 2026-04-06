<?php

declare(strict_types=1);

/**
 * AML Screening Results Recorded Event.
 */

namespace App\Domain\Compliance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Event fired when AML screening results are recorded.
 */
class AmlScreeningResultsRecorded extends ShouldBeStored
{
    /**
     * Create new AML screening results recorded event.
     */
    public function __construct(
        public array $sanctionsResults,
        public array $pepResults,
        public array $adverseMediaResults,
        public array $otherResults,
        public int $totalMatches,
        public string $overallRisk,
        public array $listsChecked,
        public ?array $apiResponse = null
    ) {
    }
}
