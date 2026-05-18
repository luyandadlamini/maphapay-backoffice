<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Shared\Contracts\CarriesTenantContext;
use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AccountFrozen extends ShouldBeStored implements CarriesTenantContext
{
    public string $queue = EventQueues::LEDGER->value;

    public function __construct(
        public readonly string $reason,
        public readonly ?string $authorizedBy = null
    ) {
    }

    public function tenantAccountUuid(): string
    {
        return (string) $this->aggregateRootUuid();
    }
}
