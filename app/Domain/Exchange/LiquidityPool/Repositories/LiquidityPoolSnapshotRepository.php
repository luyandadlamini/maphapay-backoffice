<?php

declare(strict_types=1);

namespace App\Domain\Exchange\LiquidityPool\Repositories;

use App\Domain\Exchange\LiquidityPool\Snapshots\LiquidityPoolSnapshot;
use Spatie\EventSourcing\AggregateRoots\Exceptions\InvalidEloquentStoredEventModel;
use Spatie\EventSourcing\Snapshots\EloquentSnapshot;
use Spatie\EventSourcing\Snapshots\EloquentSnapshotRepository;

final class LiquidityPoolSnapshotRepository extends EloquentSnapshotRepository
{
    /**
     * @throws InvalidEloquentStoredEventModel
     */
    public function __construct(
        protected string $snapshotModel = LiquidityPoolSnapshot::class
    ) {
        if (! new $this->snapshotModel() instanceof EloquentSnapshot) {
            throw new InvalidEloquentStoredEventModel("The class {$this->snapshotModel} must extend EloquentSnapshot");
        }
    }
}
