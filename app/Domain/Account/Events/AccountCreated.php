<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use App\Domain\Account\DataObjects\Account;
use App\Values\EventQueues;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AccountCreated extends ShouldBeStored
{
    public string $queue = EventQueues::LEDGER->value;

    public function __construct(
        public readonly Account $account
    ) {
    }
}
