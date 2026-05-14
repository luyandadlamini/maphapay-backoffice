<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

final readonly class WalletLinkResult
{
    public const LINK_STATUS_ACTIVE = 'active';

    public const LINK_STATUS_PENDING = 'pending';

    public const LINK_STATUS_FAILED = 'failed';

    public function __construct(
        public string $providerId,
        public string $providerAccountRef,
        public string $displayName,
        public string $linkToken,
        public string $linkStatus,
    ) {
    }
}
