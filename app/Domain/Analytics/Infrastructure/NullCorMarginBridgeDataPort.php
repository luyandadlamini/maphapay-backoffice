<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Infrastructure;

use App\Domain\Analytics\Contracts\CorMarginBridgeDataPort;
use App\Domain\Analytics\DTO\RevenuePresentationSlot;

/**
 * Default COR bridge port: reserved slot layout, no fabricated values.
 */
final class NullCorMarginBridgeDataPort implements CorMarginBridgeDataPort
{
    public function marginSlots(): array
    {
        return [
            new RevenuePresentationSlot(
                'gross_recognized',
                __('Gross (recognized revenue)'),
                null,
                __('Populated when finance signs recognition + mart grain (ADR-006).'),
            ),
            new RevenuePresentationSlot(
                'pass_through',
                __('Pass-through / principal'),
                null,
                __('Rail-specific pass-through lines once mapped.'),
            ),
            new RevenuePresentationSlot(
                'cor',
                __('Cost of revenue (COR)'),
                null,
                __('Authoritative COR line items per stream; no synthetic allocation.'),
            ),
            new RevenuePresentationSlot(
                'contribution_margin',
                __('Contribution margin'),
                null,
                __('Gross − COR after agreed rules; not wallet activity volume.'),
            ),
        ];
    }

    public function hasRenderableData(): bool
    {
        return false;
    }
}
