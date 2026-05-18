<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardSubscriptions\Enums\PhysicalCardOrderStatus;
use App\Domain\CardSubscriptions\Events\CardSubscriptionActivated;
use App\Domain\CardSubscriptions\Events\CardSubscriptionBillingAttempted;
use App\Domain\CardSubscriptions\Events\CardSubscriptionCancelled;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPastDue;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPlanChanged;
use App\Domain\CardSubscriptions\Events\CardSubscriptionRestored;
use App\Domain\CardSubscriptions\Events\CardSubscriptionSuspended;
use App\Domain\CardSubscriptions\Events\PhysicalCardOrderActivated;
use App\Domain\CardSubscriptions\Events\PhysicalCardOrderStatusChanged;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\Mobile\Services\PushNotificationService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class NotifyCardSubscriptionLifecycle extends Reactor implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PushNotificationService $push,
    ) {
        $this->onQueue('notifications');
    }

    public function onSubscriptionLifecycleEvent(
        CardSubscriptionActivated|CardSubscriptionPlanChanged|CardSubscriptionPastDue|CardSubscriptionSuspended|CardSubscriptionCancelled|CardSubscriptionRestored|CardSubscriptionBillingAttempted $event,
    ): void {
        $sub = CardSubscription::query()->with('plan')->find($event->subscriptionId);

        if ($sub === null) {
            return;
        }

        if ($event instanceof CardSubscriptionBillingAttempted) {
            if ($event->result !== 'success') {
                return;
            }

            $payer = User::query()->find($sub->payer_user_id);
            if ($payer === null) {
                return;
            }

            $planName = $sub->plan?->name ?? $sub->plan?->code ?? 'card plan';
            $next = $sub->next_billing_date?->toFormattedDateString() ?? '';

            $this->push->sendToUser(
                $payer,
                'cards.payment_success',
                __('cards.push.payment_success.title'),
                __('cards.push.payment_success.body', ['plan' => $planName, 'date' => $next]),
                [
                    'subscription_id' => (string) $sub->id,
                    'cta'             => 'cards.subscription.retry_payment',
                ],
            );

            return;
        }

        $subscriber = User::query()->find($sub->subscriber_user_id);
        if ($subscriber === null) {
            return;
        }

        $planName = $sub->plan?->name ?? $sub->plan?->code ?? 'card plan';

        match (true) {
            $event instanceof CardSubscriptionActivated   => $this->notifyActivated($sub, $subscriber, $planName, $event),
            $event instanceof CardSubscriptionPastDue     => $this->notifyPastDue($sub, $subscriber, $planName, $event),
            $event instanceof CardSubscriptionSuspended   => $this->notifySuspended($sub, $subscriber, $planName),
            $event instanceof CardSubscriptionCancelled   => $this->notifyCancelled($sub, $subscriber, $planName),
            $event instanceof CardSubscriptionRestored    => $this->notifyRestored($sub, $planName),
            $event instanceof CardSubscriptionPlanChanged => null,
            default                                       => null,
        };
    }

    public function onPhysicalCardOrderStatusChanged(PhysicalCardOrderStatusChanged $event): void
    {
        $notifyStatuses = [
            PhysicalCardOrderStatus::Dispatched->value,
            PhysicalCardOrderStatus::ReadyForCollection->value,
            PhysicalCardOrderStatus::Delivered->value,
        ];

        if (! in_array($event->newStatus, $notifyStatuses, true)) {
            return;
        }

        $order = PhysicalCardOrder::query()->find($event->orderId);

        if ($order === null) {
            return;
        }

        $user = User::query()->find($order->user_id);

        if ($user === null) {
            return;
        }

        $this->push->sendToUser(
            $user,
            'cards.physical_order_update',
            __('cards.push.physical_order_update.title'),
            __('cards.push.physical_order_update.body', ['status' => $event->newStatus]),
            [
                'cta' => 'cards.physical_order.status',
            ],
        );
    }

    public function onPhysicalCardOrderActivated(PhysicalCardOrderActivated $event): void
    {
        $order = PhysicalCardOrder::query()->find($event->orderId);

        if ($order === null) {
            return;
        }

        $user = User::query()->find($order->user_id);

        if ($user === null) {
            return;
        }

        $this->push->sendToUser(
            $user,
            'cards.physical_activated',
            __('cards.push.physical_activated.title'),
            __('cards.push.physical_activated.body'),
            [
                'card_id' => $event->cardId,
                'cta'     => 'cards.card.detail',
            ],
        );
    }

    private function notifyActivated(CardSubscription $sub, User $subscriber, string $planName, CardSubscriptionActivated $event): void
    {
        $this->push->sendToUser(
            $subscriber,
            'cards.subscription_activated',
            __('cards.push.subscription_activated.title'),
            __('cards.push.subscription_activated.body', ['plan' => $planName]),
            [
                'subscription_id' => (string) $sub->id,
                'cta'             => 'cards.card.detail',
            ],
        );

        if ($event->isMinorSubscription && $event->guardianUserId !== null) {
            $guardian = User::query()->find($event->guardianUserId);
            if ($guardian !== null) {
                $this->push->sendToUser(
                    $guardian,
                    'cards.subscription_activated',
                    __('cards.push.subscription_activated.title'),
                    __('cards.push.subscription_activated.body', ['plan' => $planName]),
                    [
                        'subscription_id' => (string) $sub->id,
                        'cta'             => 'cards.card.detail',
                    ],
                );
            }
        }
    }

    private function notifyPastDue(CardSubscription $sub, User $subscriber, string $planName, CardSubscriptionPastDue $event): void
    {
        $grace = $event->gracePeriodEndsAt;
        $payer = User::query()->find($sub->payer_user_id);

        if ($payer !== null) {
            $this->push->sendToUser(
                $payer,
                'cards.payment_failed',
                __('cards.push.payment_failed.title'),
                __('cards.push.payment_failed.body', ['grace_end' => $grace, 'plan' => $planName]),
                [
                    'subscription_id' => (string) $sub->id,
                    'cta'             => 'cards.subscription.retry_payment',
                ],
            );
        }

        if ($subscriber->id !== ($payer?->id)) {
            $this->push->sendToUser(
                $subscriber,
                'cards.payment_failed',
                __('cards.push.payment_failed.title'),
                __('cards.push.payment_failed.body', ['grace_end' => $grace, 'plan' => $planName]),
                [
                    'subscription_id' => (string) $sub->id,
                    'cta'             => 'cards.subscription.retry_payment',
                ],
            );
        }
    }

    private function notifySuspended(CardSubscription $sub, User $subscriber, string $planName): void
    {
        $payer = User::query()->find($sub->payer_user_id);

        if ($payer !== null) {
            $this->push->sendToUser(
                $payer,
                'cards.subscription_suspended',
                __('cards.push.subscription_suspended.title'),
                __('cards.push.subscription_suspended.body', ['plan' => $planName]),
                [
                    'subscription_id' => (string) $sub->id,
                    'cta'             => 'cards.subscription.retry_payment',
                ],
            );
        }

        if ($subscriber->id !== ($payer?->id)) {
            $this->push->sendToUser(
                $subscriber,
                'cards.subscription_suspended',
                __('cards.push.subscription_suspended.title'),
                __('cards.push.subscription_suspended.body', ['plan' => $planName]),
                [
                    'subscription_id' => (string) $sub->id,
                    'cta'             => 'cards.subscription.retry_payment',
                ],
            );
        }
    }

    private function notifyCancelled(CardSubscription $sub, User $subscriber, string $planName): void
    {
        $this->push->sendToUser(
            $subscriber,
            'cards.subscription_cancelled',
            __('cards.push.subscription_cancelled.title'),
            __('cards.push.subscription_cancelled.body', ['plan' => $planName]),
            [
                'subscription_id' => (string) $sub->id,
                'cta'             => 'cards.card.detail',
            ],
        );
    }

    private function notifyRestored(CardSubscription $sub, string $planName): void
    {
        $payer = User::query()->find($sub->payer_user_id);

        if ($payer === null) {
            return;
        }

        $this->push->sendToUser(
            $payer,
            'cards.subscription_restored',
            __('cards.push.subscription_restored.title'),
            __('cards.push.subscription_restored.body', ['plan' => $planName]),
            [
                'subscription_id' => (string) $sub->id,
                'cta'             => 'cards.card.detail',
            ],
        );
    }
}
