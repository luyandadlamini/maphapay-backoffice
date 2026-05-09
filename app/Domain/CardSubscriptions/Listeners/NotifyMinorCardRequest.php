<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardSubscriptions\Events\MinorCardRequestApproved;
use App\Domain\CardSubscriptions\Events\MinorCardRequestDenied;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class NotifyMinorCardRequest extends Reactor implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function onMinorCardRequestDecision(MinorCardRequestApproved|MinorCardRequestDenied $event): void
    {
        //
    }
}
