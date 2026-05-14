<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

final readonly class WalletMovementStatus
{
    public function __construct(
        public string $providerRequestId,
        public string $status,
        public ?string $failureReason,
        public ?int $settledAt,
    ) {
    }
}
