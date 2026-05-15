<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

final readonly class WalletDisbursementResult
{
    public function __construct(
        public int $transactionId,
        public string $providerRequestId,
        public string $status,
        public ?string $failureReason,
        public bool $isReplay,
    ) {
    }
}
