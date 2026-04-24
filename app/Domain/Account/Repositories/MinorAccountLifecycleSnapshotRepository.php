<?php

declare(strict_types=1);

namespace App\Domain\Account\Repositories;

use App\Domain\Account\Snapshots\MinorAccountLifecycleSnapshot;
use Spatie\EventSourcing\AggregateRoots\Exceptions\InvalidEloquentStoredEventModel;
use Spatie\EventSourcing\Snapshots\EloquentSnapshotRepository;

final class MinorAccountLifecycleSnapshotRepository extends EloquentSnapshotRepository
{
    public function __construct(
        protected string $snapshotModel = MinorAccountLifecycleSnapshot::class
    ) {
        if (! new $this->snapshotModel() instanceof \Spatie\EventSourcing\Snapshots\EloquentSnapshot) {
            throw new InvalidEloquentStoredEventModel("The class {$this->snapshotModel} must extend EloquentStoredEvent");
        }
    }
}