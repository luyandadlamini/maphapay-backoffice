<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Contracts\UnitEconomicsDataPort;
use App\Domain\Analytics\DTO\UnitEconomicsPageState;

/**
 * Assembles REQ-REV-004 page state from config + {@see UnitEconomicsDataPort}.
 */
final class WalletRevenueUnitEconomicsPresenter
{
    public function __construct(
        private readonly UnitEconomicsDataPort $dataPort,
    ) {
    }

    public function build(): UnitEconomicsPageState
    {
        $enabled = (bool) config('maphapay.revenue_unit_economics_enabled', false);
        $slots = $this->dataPort->economicsSlots();
        $dataAvailable = $enabled && $this->dataPort->hasRenderableData();

        return new UnitEconomicsPageState($enabled, $dataAvailable, $slots);
    }
}
