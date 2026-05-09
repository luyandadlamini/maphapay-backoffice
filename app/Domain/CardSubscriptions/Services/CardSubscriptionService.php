<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Models\User;

class CardSubscriptionService
{
    public function subscribe(User $subscriber, string $planCode, ?User $payer = null, ?string $minorRequestId = null): CardSubscription
    {
        throw new \LogicException('not implemented');
    }

    public function upgrade(User $subscriber, string $newPlanCode): CardSubscription
    {
        throw new \LogicException('not implemented');
    }

    public function downgrade(User $subscriber, string $newPlanCode, bool $force = false): CardSubscription
    {
        throw new \LogicException('not implemented');
    }

    public function cancel(User $subscriber): CardSubscription
    {
        throw new \LogicException('not implemented');
    }

    public function getCurrent(User $subscriber): ?CardSubscription
    {
        throw new \LogicException('not implemented');
    }

    public function markPastDue(CardSubscription $subscription, string $failureReason): void
    {
        throw new \LogicException('not implemented');
    }

    public function suspend(CardSubscription $subscription): void
    {
        throw new \LogicException('not implemented');
    }

    public function restore(CardSubscription $subscription): void
    {
        throw new \LogicException('not implemented');
    }

    public function terminateUnpaid(CardSubscription $subscription): void
    {
        throw new \LogicException('not implemented');
    }
}
