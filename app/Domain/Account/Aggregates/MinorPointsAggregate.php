<?php

declare(strict_types=1);

namespace App\Domain\Account\Aggregates;

use App\Domain\Account\Events\PointsAwarded;
use App\Domain\Account\Events\PointsDeducted;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class MinorPointsAggregate extends AggregateRoot
{
    private string $minorAccountUuid;

    public static function initialize(string $minorAccountUuid): static
    {
        $aggregate = self::new();
        $aggregate->minorAccountUuid = $minorAccountUuid;

        return $aggregate;
    }

    public function awardPoints(
        int $points,
        string $source,
        string $description,
        ?string $referenceId,
    ): static {
        $this->recordThat(
            new PointsAwarded(
                minorAccountUuid: $this->minorAccountUuid,
                points: abs($points),
                source: $source,
                description: $description,
                referenceId: $referenceId,
            )
        );

        return $this;
    }

    public function deductPoints(
        int $points,
        string $source,
        string $description,
        ?string $referenceId,
    ): static {
        $this->recordThat(
            new PointsDeducted(
                minorAccountUuid: $this->minorAccountUuid,
                points: abs($points),
                source: $source,
                description: $description,
                referenceId: $referenceId,
            )
        );

        return $this;
    }
}