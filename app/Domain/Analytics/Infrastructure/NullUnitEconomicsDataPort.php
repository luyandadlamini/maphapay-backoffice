<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Infrastructure;

use App\Domain\Analytics\Contracts\UnitEconomicsDataPort;
use App\Domain\Analytics\DTO\RevenuePresentationSlot;

/**
 * Default unit economics port: reserved slot layout until acquisition + lifecycle feeds exist.
 */
final class NullUnitEconomicsDataPort implements UnitEconomicsDataPort
{
    public function economicsSlots(): array
    {
        return [
            new RevenuePresentationSlot(
                'cac',
                __('CAC (blended)'),
                null,
                __('Marketing spend ÷ attributed wallet acquisitions for the chosen cohort window.'),
            ),
            new RevenuePresentationSlot(
                'ltv',
                __('LTV (discounted / agreed definition)'),
                null,
                __('Requires finance-approved revenue attribution and horizon.'),
            ),
            new RevenuePresentationSlot(
                'ltv_cac',
                __('LTV : CAC'),
                null,
                null,
            ),
            new RevenuePresentationSlot(
                'payback_months',
                __('Payback (months)'),
                null,
                null,
            ),
            new RevenuePresentationSlot(
                'cohort_label',
                __('Cohort / window'),
                null,
                __('Example: “2026-Q1 marketing cohort, ZAR reporting”.'),
            ),
        ];
    }

    public function hasRenderableData(): bool
    {
        return false;
    }
}
