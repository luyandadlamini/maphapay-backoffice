<?php

declare(strict_types=1);

namespace App\Domain\Analytics\DTO;

/**
 * View model for REQ-REV-004 unit economics surface.
 *
 * @phpstan-type SlotList list<RevenuePresentationSlot>
 */
final readonly class UnitEconomicsPageState
{
    /**
     * @param  SlotList  $slots
     */
    public function __construct(
        public bool $featureEnabled,
        public bool $dataAvailable,
        public array $slots,
    ) {
    }
}
