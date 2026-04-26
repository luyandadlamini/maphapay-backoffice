<?php

declare(strict_types=1);

namespace App\Domain\Analytics\DTO;

/**
 * View model for REQ-REV-003 profitability / COR margin bridge surface.
 *
 * @phpstan-type SlotList list<RevenuePresentationSlot>
 */
final readonly class CorMarginBridgePageState
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
