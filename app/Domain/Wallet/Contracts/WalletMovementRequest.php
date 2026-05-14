<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

final readonly class WalletMovementRequest
{
    public function __construct(
        public string $providerId,
        public string $providerAccountRef,
        public string $linkToken,
        public int $amountMinor,
        public string $currency,
        public string $idempotencyKey,
        public string $callbackUrl,
        public string $memo,
    ) {
    }
}
