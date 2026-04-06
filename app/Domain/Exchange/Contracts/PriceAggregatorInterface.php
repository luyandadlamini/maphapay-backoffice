<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

interface PriceAggregatorInterface
{
    /**
     * Get aggregated price data.
     */
    public function getAggregatedPrice(string $symbol): array;

    /**
     * Get best bid across exchanges.
     */
    public function getBestBid(string $symbol): array;

    /**
     * Get best ask across exchanges.
     */
    public function getBestAsk(string $symbol): array;
}
