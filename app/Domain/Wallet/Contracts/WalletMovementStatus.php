<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

final readonly class WalletMovementStatus
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $providerRequestId,
        public string $status,
        public ?string $failureReason,
        /** Unix timestamp in seconds, UTC; null if not yet settled. */
        public ?int $settledAt,
    ) {
    }
}
