<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Hash;
use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AssetTransferred extends ShouldBeStored implements HasHash
{
    use HashValidatorProvider;

    public string $queue = EventQueues::TRANSFERS->value;

    public function __construct(
        public readonly AccountUuid $from,
        public readonly AccountUuid $to,
        public readonly string $assetCode,
        public readonly int $amount,
        public readonly Hash $hash,
        public readonly ?array $metadata = []
    ) {
    }

    /**
     * Get the amount for this event.
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * Get the asset code for this event.
     */
    public function getAssetCode(): string
    {
        return $this->assetCode;
    }

    /**
     * Get the from account UUID.
     */
    public function getFromAccount(): string
    {
        return $this->from->getUuid();
    }

    /**
     * Get the to account UUID.
     */
    public function getToAccount(): string
    {
        return $this->to->getUuid();
    }
}
