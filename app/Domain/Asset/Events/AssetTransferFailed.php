<?php

declare(strict_types=1);

namespace App\Domain\Asset\Events;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Shared\Contracts\CarriesTenantContext;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AssetTransferFailed extends ShouldBeStored implements CarriesTenantContext
{
    public function __construct(
        public readonly AccountUuid $fromAccountUuid,
        public readonly AccountUuid $toAccountUuid,
        public readonly string $fromAssetCode,
        public readonly string $toAssetCode,
        public readonly Money $fromAmount,
        public readonly string $reason,
        public readonly Hash $hash,
        public readonly ?string $transferId = null,
        public readonly ?array $metadata = null
    ) {
    }

    /**
     * Get the failure reason.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Check if failure was due to insufficient balance.
     */
    public function isInsufficientBalance(): bool
    {
        return str_contains(strtolower($this->reason), 'insufficient');
    }

    /**
     * Check if failure was due to exchange rate issues.
     */
    public function isExchangeRateFailure(): bool
    {
        return str_contains(strtolower($this->reason), 'exchange') ||
               str_contains(strtolower($this->reason), 'rate');
    }

    public function tenantAccountUuid(): string
    {
        return (string) $this->fromAccountUuid;
    }
}
