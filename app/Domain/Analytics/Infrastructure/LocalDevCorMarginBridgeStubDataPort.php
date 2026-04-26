<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Infrastructure;

use App\Domain\Analytics\Contracts\CorMarginBridgeDataPort;
use App\Domain\Analytics\DTO\RevenuePresentationSlot;

/**
 * Non-production stub for wiring / UI tests (REQ-REV-003).
 *
 * Enable with {@see config('maphapay.revenue_cor_bridge_stub_reader')} outside production.
 * Values are obviously synthetic — never use for finance decisions.
 */
final class LocalDevCorMarginBridgeStubDataPort implements CorMarginBridgeDataPort
{
    public function marginSlots(): array
    {
        $stub = __('STUB — not finance data');

        return [
            new RevenuePresentationSlot(
                'gross_recognized',
                __('Gross (recognized revenue)'),
                'ZAR 12,345.67',
                $stub,
            ),
            new RevenuePresentationSlot(
                'pass_through',
                __('Pass-through / principal'),
                'ZAR 8,000.00',
                $stub,
            ),
            new RevenuePresentationSlot(
                'cor',
                __('Cost of revenue (COR)'),
                'ZAR 3,100.00',
                $stub,
            ),
            new RevenuePresentationSlot(
                'contribution_margin',
                __('Contribution margin'),
                'ZAR 1,245.67',
                $stub,
            ),
        ];
    }

    public function hasRenderableData(): bool
    {
        return true;
    }
}
