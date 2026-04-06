<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Events;

final class EnhancedLiquidityPoolCreated extends BasePoolEvent
{
    public function __construct(
        public readonly string $poolId,
        public readonly string $baseCurrency,
        public readonly string $quoteCurrency,
        public readonly string $feeRate,
        public readonly array $poolParameters = [],
        public readonly array $metadata = []
    ) {
        parent::__construct();

        // Add pool-specific metadata
        $this->eventMetadata['pool_creation'] = [
            'pair'             => "{$baseCurrency}/{$quoteCurrency}",
            'fee_basis_points' => (int) ($feeRate * 10000),
            'creator_type'     => $metadata['creator_type'] ?? 'user',
            'initial_tvl'      => $metadata['initial_tvl'] ?? '0',
            'pool_type'        => $metadata['pool_type'] ?? 'constant_product',
        ];
    }
}
