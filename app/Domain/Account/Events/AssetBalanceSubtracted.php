<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Account\DataObjects\Hash;
use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AssetBalanceSubtracted extends ShouldBeStored implements HasHash
{
    use HashValidatorProvider;

    public string $queue = EventQueues::TRANSACTIONS->value;

    public function __construct(
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
}
