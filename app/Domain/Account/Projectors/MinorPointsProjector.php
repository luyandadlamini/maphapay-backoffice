<?php

declare(strict_types=1);

namespace App\Domain\Account\Projectors;

use App\Domain\Account\Events\PointsAwarded;
use App\Domain\Account\Events\PointsDeducted;
use App\Domain\Account\Models\MinorPointsLedger;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class MinorPointsProjector extends Projector
{
    public function onPointsAwarded(PointsAwarded $event): void
    {
        MinorPointsLedger::create([
            'minor_account_uuid' => $event->minorAccountUuid,
            'points' => $event->points,
            'source' => $event->source,
            'description' => $event->description,
            'reference_id' => $event->referenceId,
        ]);
    }

    public function onPointsDeducted(PointsDeducted $event): void
    {
        MinorPointsLedger::create([
            'minor_account_uuid' => $event->minorAccountUuid,
            'points' => -$event->points,
            'source' => $event->source,
            'description' => $event->description,
            'reference_id' => $event->referenceId,
        ]);
    }
}