<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MandateCreated extends ShouldBeStored
{
    use Dispatchable;

    public function __construct(
        public readonly string $mandateId,
        public readonly string $type,
        public readonly string $issuerDid,
        public readonly string $subjectDid,
        public readonly int $amountCents,
        public readonly string $currency,
    ) {
    }
}
