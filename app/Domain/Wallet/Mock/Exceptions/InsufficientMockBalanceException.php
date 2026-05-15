<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Mock\Exceptions;

use RuntimeException;

final class InsufficientMockBalanceException extends RuntimeException
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $accountRef,
        public readonly int $balanceMinor,
        public readonly int $debitAmountMinor,
    ) {
        parent::__construct(
            "Mock balance insufficient on {$providerId}:{$accountRef} "
            . "({$balanceMinor} minor < {$debitAmountMinor} minor).",
        );
    }
}
