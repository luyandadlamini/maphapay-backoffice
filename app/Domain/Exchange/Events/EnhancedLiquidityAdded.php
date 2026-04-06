<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events;

use Brick\Math\BigDecimal;

final class EnhancedLiquidityAdded extends BasePoolEvent
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $providerId,
        public readonly string $baseAmount,
        public readonly string $quoteAmount,
        public readonly string $sharesMinted,
        public readonly string $newBaseReserve,
        public readonly string $newQuoteReserve,
        public readonly string $newTotalShares,
        public readonly array $metadata = []
    ) {
        parent::__construct();

        // Calculate and add detailed metrics
        $this->eventMetadata['liquidity_metrics'] = [
            'provider_share_percentage'   => $this->calculateSharePercentage(),
            'price_impact'                => $this->calculatePriceImpact(),
            'tvl_change'                  => $this->calculateTvlChange(),
            'is_first_provider'           => BigDecimal::of($newTotalShares)->isEqualTo($sharesMinted),
            'provider_position_value_usd' => $metadata['position_value_usd'] ?? null,
            'gas_used'                    => $metadata['gas_used'] ?? null,
            'execution_time_ms'           => $metadata['execution_time_ms'] ?? null,
        ];

        // Add compliance metadata
        $this->eventMetadata['compliance'] = [
            'kyc_verified'     => $metadata['kyc_verified'] ?? false,
            'aml_check_passed' => $metadata['aml_check_passed'] ?? true,
            'jurisdiction'     => $metadata['jurisdiction'] ?? null,
            'source_of_funds'  => $metadata['source_of_funds'] ?? 'unknown',
        ];
    }

    private function calculateSharePercentage(): string
    {
        return BigDecimal::of($this->sharesMinted)
            ->dividedBy($this->newTotalShares, 18)
            ->multipliedBy(100)
            ->toScale(2)
            ->__toString();
    }

    private function calculatePriceImpact(): string
    {
        $oldPrice = BigDecimal::of($this->newQuoteReserve)
            ->minus($this->quoteAmount)
            ->dividedBy(
                BigDecimal::of($this->newBaseReserve)->minus($this->baseAmount),
                18
            );

        $newPrice = BigDecimal::of($this->newQuoteReserve)
            ->dividedBy($this->newBaseReserve, 18);

        return $newPrice->minus($oldPrice)
            ->dividedBy($oldPrice, 18)
            ->abs()
            ->multipliedBy(100)
            ->toScale(4)
            ->__toString();
    }

    private function calculateTvlChange(): array
    {
        return [
            'base_added'          => $this->baseAmount,
            'quote_added'         => $this->quoteAmount,
            'estimated_usd_value' => $this->metadata['usd_value'] ?? null,
        ];
    }
}
