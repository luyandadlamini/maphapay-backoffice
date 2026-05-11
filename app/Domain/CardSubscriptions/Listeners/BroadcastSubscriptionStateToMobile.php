<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardSubscriptions\Events\Broadcast\CardSubscriptionStateUpdated;
use App\Domain\CardSubscriptions\Events\CardSubscriptionActivated;
use App\Domain\CardSubscriptions\Events\CardSubscriptionCancelled;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPastDue;
use App\Domain\CardSubscriptions\Events\CardSubscriptionRestored;
use App\Domain\CardSubscriptions\Events\CardSubscriptionSuspended;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class BroadcastSubscriptionStateToMobile extends Reactor implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function onSubscriptionStateChanged(
        CardSubscriptionActivated|CardSubscriptionPastDue|CardSubscriptionSuspended|CardSubscriptionRestored|CardSubscriptionCancelled $event,
    ): void {
        $sub = CardSubscription::query()->with('plan')->find($event->subscriptionId);

        if ($sub === null) {
            return;
        }

        $status = $sub->status->value;

        broadcast(new CardSubscriptionStateUpdated(
            userId: (int) $sub->subscriber_user_id,
            subscriptionId: (string) $sub->id,
            status: $status,
            payload: [
                'plan_code' => $sub->plan?->code,
            ],
        ));
    }
}
