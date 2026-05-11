<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardIssuance\Events\AuthorizationApproved;
use App\Domain\CardIssuance\Events\AuthorizationDeclined;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\Mobile\Services\PushNotificationService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyCardJitAuthorization implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PushNotificationService $push,
    ) {
        $this->onQueue('notifications');
    }

    public function __invoke(AuthorizationApproved|AuthorizationDeclined $event): void
    {
        $card = Card::query()->where('issuer_card_token', $event->cardToken)->first();

        if ($card === null) {
            return;
        }

        $user = User::query()->find($card->user_id);

        if ($user === null) {
            return;
        }

        if ($event instanceof AuthorizationApproved) {
            $this->push->sendToUser(
                $user,
                'cards.transaction_approved',
                __('cards.push.transaction_approved.title'),
                __('cards.push.transaction_approved.body', [
                    'merchant' => $event->merchantName,
                    'amount'   => (string) $event->amount,
                ]),
                [
                    'card_id' => (string) $card->id,
                    'cta'     => 'cards.card.detail',
                ],
            );

            return;
        }

        $this->push->sendToUser(
            $user,
            'cards.transaction_declined',
            __('cards.push.transaction_declined.title'),
            __('cards.push.transaction_declined.body', [
                'merchant' => $event->merchantName,
                'reason'   => $event->reason->getMessage(),
            ]),
            [
                'card_id' => (string) $card->id,
                'cta'     => 'cards.card.detail',
            ],
        );
    }
}
