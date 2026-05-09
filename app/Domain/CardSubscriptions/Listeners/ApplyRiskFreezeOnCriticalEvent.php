<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardSubscriptions\Events\CardRiskEventOpened;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class ApplyRiskFreezeOnCriticalEvent extends Reactor implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function onCardRiskEventOpened(CardRiskEventOpened $event): void
    {
        //
    }
}
