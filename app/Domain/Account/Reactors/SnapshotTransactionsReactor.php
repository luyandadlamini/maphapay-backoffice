<?php

declare(strict_types=1);

namespace App\Domain\Account\Reactors;

use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\Events\TransactionThresholdReached;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class SnapshotTransactionsReactor extends Reactor
{
    public function __construct(
        protected TransactionAggregate $transactions,
    ) {
    }

    public function onTransactionThresholdReached(
        TransactionThresholdReached $event
    ): void {
        $aggregate = $this->transactions->loadUuid(
            $event->aggregateRootUuid()
        );
        $aggregate->snapshot();  // Take the snapshot
    }
}
