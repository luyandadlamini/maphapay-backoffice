<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardSubscriptions\Events\CardFeeCharged;
use App\Domain\Mobile\Services\PushNotificationService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class NotifyCardFeeCharged extends Reactor implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PushNotificationService $push,
    ) {
        $this->onQueue('notifications');
    }

    public function onCardFeeCharged(CardFeeCharged $event): void
    {
        if (! in_array($event->feeType, ['subscription', 'physical_card_issuance', 'physical_card_replacement'], true)) {
            return;
        }

        $user = User::query()->find($event->userId);

        if ($user === null) {
            return;
        }

        if ($event->feeType === 'subscription') {
            $this->push->sendToUser(
                $user,
                'cards.fee_subscription',
                __('cards.push.fee_subscription.title'),
                __('cards.push.fee_subscription.body', ['amount' => $event->amount]),
                [
                    'cta' => 'cards.card.detail',
                ],
            );

            return;
        }

        $this->push->sendToUser(
            $user,
            'cards.fee_physical',
            __('cards.push.fee_physical.title'),
            __('cards.push.fee_physical.body', ['amount' => $event->amount]),
            [
                'cta' => 'cards.physical_order.status',
            ],
        );
    }
}
