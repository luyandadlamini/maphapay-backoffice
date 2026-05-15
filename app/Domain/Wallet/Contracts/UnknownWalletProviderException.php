<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

use RuntimeException;

final class UnknownWalletProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $providerId,
    ) {
        parent::__construct("Unknown wallet provider: {$providerId}");
    }
}
