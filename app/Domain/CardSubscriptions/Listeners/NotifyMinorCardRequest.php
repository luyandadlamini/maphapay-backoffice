<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\Account\Models\Account;
use App\Domain\CardSubscriptions\Events\MinorCardRequestApproved;
use App\Domain\CardSubscriptions\Events\MinorCardRequestDenied;
use App\Domain\Mobile\Services\PushNotificationService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class NotifyMinorCardRequest extends Reactor implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PushNotificationService $push,
    ) {
        $this->onQueue('notifications');
    }

    public function onMinorCardRequestDecision(MinorCardRequestApproved|MinorCardRequestDenied $event): void
    {
        $minorUser = $this->resolveMinorUser($event->minorAccountUuid);

        if ($minorUser === null) {
            return;
        }

        if ($event instanceof MinorCardRequestApproved) {
            $this->push->sendToUser(
                $minorUser,
                'cards.minor_request_approved',
                __('cards.push.minor_request_approved.title'),
                __('cards.push.minor_request_approved.body'),
                [
                    'cta' => 'cards.card.detail',
                ],
            );

            return;
        }

        $this->push->sendToUser(
            $minorUser,
            'cards.minor_request_denied',
            __('cards.push.minor_request_denied.title'),
            __('cards.push.minor_request_denied.body', ['reason' => $event->denialReason ?? '']),
            [
                'cta' => 'cards.card.detail',
            ],
        );
    }

    private function resolveMinorUser(string $minorAccountUuid): ?User
    {
        $account = Account::query()->where('uuid', $minorAccountUuid)->first();

        if ($account === null) {
            return null;
        }

        return User::query()->where('uuid', (string) $account->user_uuid)->first();
    }
}
