<?php

declare(strict_types=1);

namespace App\Domain\Account\Reactors;

use App\Domain\Account\Aggregates\TransferAggregate;
use App\Domain\Account\Events\TransferThresholdReached;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class SnapshotTransfersReactor extends Reactor
{
    public function __construct(
        protected TransferAggregate $transfers,
    ) {
    }

    public function onTransferThresholdReached(
        TransferThresholdReached $event
    ): void {
        $aggregate = $this->transfers->loadUuid(
            $event->aggregateRootUuid()
        );
        $aggregate->snapshot();  // Take the snapshot
    }
}
