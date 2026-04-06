<?php

declare(strict_types=1);

namespace App\Domain\Account\Projectors;

use App\Domain\Account\Actions\UpdateTurnover;
use App\Domain\Account\Events\MoneyAdded;
use App\Domain\Account\Events\MoneySubtracted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class TurnoverProjector extends Projector implements ShouldQueue
{
    public function onMoneyAdded(MoneyAdded $event): void
    {
        app(UpdateTurnover::class)($event);
    }

    public function onMoneySubtracted(MoneySubtracted $event): void
    {
        app(UpdateTurnover::class)($event);
    }
}
