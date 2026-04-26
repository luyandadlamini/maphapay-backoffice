<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Contracts;

use App\Domain\Analytics\DTO\RevenuePresentationSlot;

/**
 * Finance / mart feed for COR-backed margin bridge (REQ-REV-003).
 *
 * Bind a concrete implementation in {@see \App\Providers\AppServiceProvider} when
 * cost-of-revenue snapshots exist. Until then the null adapter exposes reserved UI slots only.
 */
interface CorMarginBridgeDataPort
{
    /**
     * @return list<RevenuePresentationSlot>
     */
    public function marginSlots(): array;

    /**
     * True when at least one slot should show a non-placeholder value for the current context.
     */
    public function hasRenderableData(): bool;
}
