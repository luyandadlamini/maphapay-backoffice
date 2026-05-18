<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Account\DataObjects\Account;
use App\Domain\Shared\Contracts\CarriesTenantContext;
use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AccountCreated extends ShouldBeStored implements CarriesTenantContext
{
    public string $queue = EventQueues::LEDGER->value;

    public function __construct(
        public readonly Account $account
    ) {
    }

    public function tenantAccountUuid(): string
    {
        return (string) $this->aggregateRootUuid();
    }
}
