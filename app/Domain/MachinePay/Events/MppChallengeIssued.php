<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MppChallengeIssued extends ShouldBeStored
{
    use Dispatchable;

    public function __construct(
        public readonly string $challengeId,
        public readonly string $resourceId,
        public readonly int $amountCents,
        public readonly string $currency,
        /** @var array<string> */
        public readonly array $availableRails,
    ) {
    }
}
