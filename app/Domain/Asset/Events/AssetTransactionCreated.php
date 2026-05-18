<?php

declare(strict_types=1);

namespace App\Domain\Asset\Events;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Shared\Contracts\CarriesTenantContext;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AssetTransactionCreated extends ShouldBeStored implements CarriesTenantContext
{
    public function __construct(
        public readonly AccountUuid $accountUuid,
        public readonly string $assetCode,
        public readonly Money $money,
        public readonly string $type, // 'credit' or 'debit'
        public readonly Hash $hash,
        public readonly ?string $description = null,
        public readonly ?array $metadata = null
    ) {
    }

    public function tenantAccountUuid(): string
    {
        return (string) $this->accountUuid;
    }

    /**
     * Get the transaction type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Check if this is a credit transaction.
     */
    public function isCredit(): bool
    {
        return $this->type === 'credit';
    }

    /**
     * Check if this is a debit transaction.
     */
    public function isDebit(): bool
    {
        return $this->type === 'debit';
    }

    /**
     * Get the amount in smallest unit.
     */
    public function getAmount(): int
    {
        return $this->money->getAmount();
    }
}
