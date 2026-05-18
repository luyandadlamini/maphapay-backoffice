<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Shared\Contracts\CarriesTenantContext;
use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AccountDeleted extends ShouldBeStored implements CarriesTenantContext
{
    public string $queue = EventQueues::LEDGER->value;

    public function tenantAccountUuid(): string
    {
        return (string) $this->aggregateRootUuid();
    }
}
