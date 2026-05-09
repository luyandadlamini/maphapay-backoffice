<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardSubscriptions\Events\CardSubscriptionActivated;
use App\Domain\CardSubscriptions\Events\CardSubscriptionBillingAttempted;
use App\Domain\CardSubscriptions\Events\CardSubscriptionCancelled;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPastDue;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPlanChanged;
use App\Domain\CardSubscriptions\Events\CardSubscriptionRestored;
use App\Domain\CardSubscriptions\Events\CardSubscriptionSuspended;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class NotifyCardSubscriptionLifecycle extends Reactor implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function onSubscriptionLifecycleEvent(
        CardSubscriptionActivated|CardSubscriptionPlanChanged|CardSubscriptionPastDue|CardSubscriptionSuspended|CardSubscriptionCancelled|CardSubscriptionRestored|CardSubscriptionBillingAttempted $event,
    ): void {
        //
    }
}
