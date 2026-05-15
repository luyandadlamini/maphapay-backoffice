<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services\Results;

final readonly class BalanceResult
{
    public function __construct(
        public int $balanceMinor,
        public string $currency,
        public ?string $lastUpdated,
    ) {
    }
}
