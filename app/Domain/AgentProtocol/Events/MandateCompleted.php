<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MandateCompleted extends ShouldBeStored
{
    use Dispatchable;

    public function __construct(
        public readonly string $mandateId,
    ) {
    }
}
