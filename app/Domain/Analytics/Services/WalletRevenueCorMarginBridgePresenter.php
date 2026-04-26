<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Contracts\CorMarginBridgeDataPort;
use App\Domain\Analytics\DTO\CorMarginBridgePageState;

/**
 * Assembles REQ-REV-003 page state from config + {@see CorMarginBridgeDataPort}.
 */
final class WalletRevenueCorMarginBridgePresenter
{
    public function __construct(
        private readonly CorMarginBridgeDataPort $dataPort,
    ) {
    }

    public function build(): CorMarginBridgePageState
    {
        $enabled = (bool) config('maphapay.revenue_cor_bridge_enabled', false);
        $slots = $this->dataPort->marginSlots();
        $dataAvailable = $enabled && $this->dataPort->hasRenderableData();

        return new CorMarginBridgePageState($enabled, $dataAvailable, $slots);
    }
}
