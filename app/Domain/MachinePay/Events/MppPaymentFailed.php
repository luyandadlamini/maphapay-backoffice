<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MppPaymentFailed extends ShouldBeStored
{
    use Dispatchable;

    public function __construct(
        public readonly string $challengeId,
        public readonly string $rail,
        public readonly string $reason,
    ) {
    }
}
