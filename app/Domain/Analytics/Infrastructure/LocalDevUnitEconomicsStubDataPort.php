<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Infrastructure;

use App\Domain\Analytics\Contracts\UnitEconomicsDataPort;
use App\Domain\Analytics\DTO\RevenuePresentationSlot;

/**
 * Non-production stub for wiring / UI tests (REQ-REV-004).
 *
 * Enable with {@see config('maphapay.revenue_unit_economics_stub_reader')} outside production.
 */
final class LocalDevUnitEconomicsStubDataPort implements UnitEconomicsDataPort
{
    public function economicsSlots(): array
    {
        $stub = __('STUB — not finance data');

        return [
            new RevenuePresentationSlot('cac', __('CAC (blended)'), 'ZAR 42.00', $stub),
            new RevenuePresentationSlot('ltv', __('LTV (discounted / agreed definition)'), 'ZAR 380.00', $stub),
            new RevenuePresentationSlot('ltv_cac', __('LTV:CAC'), '9.05', $stub),
            new RevenuePresentationSlot('payback_months', __('Payback (months)'), '4.2', $stub),
            new RevenuePresentationSlot('cohort_label', __('Cohort / window'), '2026-Q1 (stub)', $stub),
        ];
    }

    public function hasRenderableData(): bool
    {
        return true;
    }
}
