<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

use Brick\Math\BigDecimal;

interface FeeCalculatorInterface
{
    /**
     * Calculate trading fees for both maker and taker.
     */
    public function calculateFees(
        BigDecimal $amount,
        BigDecimal $price,
        string $takerAccountId,
        string $makerAccountId
    ): object;

    /**
     * Calculate minimum order value.
     */
    public function calculateMinimumOrderValue(string $baseCurrency, string $quoteCurrency): BigDecimal;
}
