<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Contracts;

use App\Domain\Analytics\DTO\RevenuePresentationSlot;

/**
 * Marketing + finance feeds for CAC / LTV style metrics (REQ-REV-004).
 *
 * Replace the null adapter with a tenant-scoped reader once cohort contracts exist.
 */
interface UnitEconomicsDataPort
{
    /**
     * @return list<RevenuePresentationSlot>
     */
    public function economicsSlots(): array;

    public function hasRenderableData(): bool;
}
