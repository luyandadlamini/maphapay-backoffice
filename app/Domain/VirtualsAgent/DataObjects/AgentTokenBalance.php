<?php

declare(strict_types=1);

namespace App\Domain\VirtualsAgent\DataObjects;

final class AgentTokenBalance
{
    public function __construct(
        public readonly string $tokenAddress,
        public readonly string $tokenSymbol,
        public readonly string $balance,
        public readonly ?float $priceUsd = null,
        public readonly ?float $valueUsd = null,
        public readonly string $chain = 'base',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tokenAddress' => $this->tokenAddress,
            'tokenSymbol'  => $this->tokenSymbol,
            'balance'      => $this->balance,
            'priceUsd'     => $this->priceUsd,
            'valueUsd'     => $this->valueUsd,
            'chain'        => $this->chain,
        ];
    }
}
