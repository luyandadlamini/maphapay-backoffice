<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MandateExecuted extends ShouldBeStored
{
    use Dispatchable;

    public function __construct(
        public readonly string $mandateId,
        public readonly string $paymentMethod,
        public readonly string $paymentReference,
    ) {
    }
}
