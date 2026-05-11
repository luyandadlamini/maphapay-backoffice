<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardSubscriptions\Enums\CardRiskSeverity;
use App\Domain\CardSubscriptions\Events\CardRiskEventOpened;
use App\Domain\CardSubscriptions\Services\CardRiskService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class ApplyRiskFreezeOnCriticalEvent extends Reactor implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CardRiskService $risk,
    ) {
        $this->onQueue('notifications');
    }

    public function onCardRiskEventOpened(CardRiskEventOpened $event): void
    {
        $severity = CardRiskSeverity::tryFrom($event->severity);

        if ($severity === null) {
            return;
        }

        if (! in_array($severity, [CardRiskSeverity::High, CardRiskSeverity::Critical], true)) {
            return;
        }

        $user = User::query()->find($event->userId);

        if ($user === null) {
            return;
        }

        $this->risk->suspendCardsForUser($user, $event->eventType);
    }
}
