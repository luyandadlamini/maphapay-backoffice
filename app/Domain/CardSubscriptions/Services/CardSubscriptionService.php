<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardSubscriptions\Enums\CardErrorCode;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Events\CardSubscriptionActivated;
use App\Domain\CardSubscriptions\Events\CardSubscriptionCancelled;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPastDue;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPlanChanged;
use App\Domain\CardSubscriptions\Events\CardSubscriptionRestored;
use App\Domain\CardSubscriptions\Events\CardSubscriptionSuspended;
use App\Domain\CardSubscriptions\Exceptions\EntitlementDeniedException;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CardSubscriptionService
{
    public function __construct(
        private readonly CardEntitlementService $entitlements,
        private readonly CardBillingService $billing,
        private readonly CardAuditService $audit,
    ) {}

    /**
     * Subscribe a user to a card plan.
     *
     * The billing charge is attempted inside the transaction; if it throws the
     * transaction rolls back automatically and the subscription is never persisted.
     */
    public function subscribe(User $subscriber, string $planCode, ?User $payer = null, ?string $minorRequestId = null): CardSubscription
    {
        $decision = $this->entitlements->canSubscribeToPlan($subscriber, $planCode);

        if (! $decision->allowed) {
            throw new EntitlementDeniedException(
                $decision->code ?? CardErrorCode::PLAN_NOT_AVAILABLE,
                $decision->message ?? 'Subscription not allowed.',
            );
        }

        $payer = $payer ?? $subscriber;

        $plan = CardPlan::where('code', $planCode)
            ->where('active', true)
            ->firstOrFail();

        $isMinor = $payer->id !== $subscriber->id;

        $subscription = DB::transaction(function () use ($subscriber, $payer, $plan, $isMinor, $minorRequestId): CardSubscription {
            $subscription = CardSubscription::create([
                'subscriber_user_id'   => $subscriber->id,
                'payer_user_id'        => $payer->id,
                'card_plan_id'         => $plan->id,
                'status'               => CardSubscriptionStatus::Active,
                'current_period_start' => now(),
                'current_period_end'   => now()->addMonth(),
                'next_billing_date'    => now()->addMonth(),
                'failed_payment_count' => 0,
                'is_minor_subscription' => $isMinor,
                'guardian_user_id'     => $isMinor ? $payer->id : null,
                'minor_card_request_id' => $minorRequestId,
            ]);

            // Charge the initial period. If the billing stub throws, the
            // transaction rolls back and the subscription is never committed.
            $billingResult = $this->billing->chargeInitialPeriod($subscription);

            $this->audit->recordSubscriptionEvent('subscription.created', $subscription, null);

            $billedAmountDecimal = $billingResult->amountCents !== null
                ? number_format($billingResult->amountCents / 100, 2, '.', '')
                : '0.00';

            event(new CardSubscriptionActivated(
                subscriptionId:      (string) $subscription->id,
                subscriberUserId:    (string) $subscriber->id,
                payerUserId:         (string) $payer->id,
                planCode:            $plan->code,
                billedAmount:        $billedAmountDecimal,
                currentPeriodStart:  $subscription->current_period_start->toIso8601String(),
                currentPeriodEnd:    $subscription->current_period_end->toIso8601String(),
                isMinorSubscription: $isMinor,
                guardianUserId:      $isMinor ? (string) $payer->id : null,
            ));

            return $subscription;
        });

        return $subscription;
    }

    /**
     * Upgrade a subscriber to a higher-tier plan.
     */
    public function upgrade(User $subscriber, string $newPlanCode): CardSubscription
    {
        $subscription = $this->getCurrent($subscriber);

        if ($subscription === null) {
            throw new EntitlementDeniedException(
                CardErrorCode::SUBSCRIPTION_REQUIRED,
                'No active subscription found to upgrade.',
            );
        }

        $decision = $this->entitlements->canSubscribeToPlan($subscriber, $newPlanCode);

        if (! $decision->allowed && $decision->code !== CardErrorCode::DUPLICATE_SUBSCRIPTION) {
            throw new EntitlementDeniedException(
                $decision->code ?? CardErrorCode::PLAN_NOT_AVAILABLE,
                $decision->message ?? 'Upgrade not allowed.',
            );
        }

        $newPlan = CardPlan::where('code', $newPlanCode)
            ->where('active', true)
            ->firstOrFail();

        $oldPlanCode = $subscription->plan?->code ?? '';

        DB::transaction(function () use ($subscription, $newPlan): void {
            $locked = CardSubscription::where('id', $subscription->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->card_plan_id = $newPlan->id;
            $locked->save();
            $subscription->card_plan_id = $newPlan->id;
        });

        $subscription->refresh();

        $this->audit->recordSubscriptionEvent('subscription.upgraded', $subscription, null);

        event(new CardSubscriptionPlanChanged(
            subscriptionId: (string) $subscription->id,
            oldPlanCode:    $oldPlanCode,
            newPlanCode:    $newPlan->code,
            prorationAmount: '0.00',
        ));

        return $subscription;
    }

    /**
     * Downgrade a subscriber to a lower-tier plan.
     *
     * If the subscriber holds more active virtual cards than the new plan
     * allows, pass $force = true to freeze the excess (latest first); otherwise
     * an EntitlementDeniedException is thrown.
     */
    public function downgrade(User $subscriber, string $newPlanCode, bool $force = false): CardSubscription
    {
        $subscription = $this->getCurrent($subscriber);

        if ($subscription === null) {
            throw new EntitlementDeniedException(
                CardErrorCode::SUBSCRIPTION_REQUIRED,
                'No active subscription found to downgrade.',
            );
        }

        $decision = $this->entitlements->canSubscribeToPlan($subscriber, $newPlanCode);

        if (! $decision->allowed && $decision->code !== CardErrorCode::DUPLICATE_SUBSCRIPTION) {
            throw new EntitlementDeniedException(
                $decision->code ?? CardErrorCode::PLAN_NOT_AVAILABLE,
                $decision->message ?? 'Downgrade not allowed.',
            );
        }

        $newPlan = CardPlan::where('code', $newPlanCode)
            ->where('active', true)
            ->firstOrFail();

        $activeVirtualCount = $subscription->cards()
            ->where('status', 'active')
            ->where('kind', 'virtual')
            ->count();

        $excess = $activeVirtualCount - $newPlan->max_virtual_cards;

        if ($excess > 0 && ! $force) {
            throw new EntitlementDeniedException(
                CardErrorCode::VIRTUAL_CARD_LIMIT_REACHED,
                'Downgrade requires card reduction.',
            );
        }

        $oldPlanCode = $subscription->plan?->code ?? '';

        DB::transaction(function () use ($subscription, $newPlan, $excess): void {
            $locked = CardSubscription::where('id', $subscription->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->card_plan_id = $newPlan->id;
            $locked->save();
            $subscription->card_plan_id = $newPlan->id;

            if ($excess > 0) {
                // Freeze excess virtual cards, latest-created first.
                $cardsToFreeze = $locked->cards()
                    ->where('status', 'active')
                    ->where('kind', 'virtual')
                    ->orderByDesc('created_at')
                    ->limit($excess)
                    ->get();

                foreach ($cardsToFreeze as $card) {
                    $card->status = 'frozen_by_user';
                    $card->save();
                }
            }
        });

        $subscription->refresh();

        $this->audit->recordSubscriptionEvent('subscription.downgraded', $subscription, null);

        event(new CardSubscriptionPlanChanged(
            subscriptionId:  (string) $subscription->id,
            oldPlanCode:     $oldPlanCode,
            newPlanCode:     $newPlan->code,
            prorationAmount: '0.00',
        ));

        return $subscription;
    }

    /**
     * Cancel a subscriber's active subscription immediately.
     */
    public function cancel(User $subscriber): CardSubscription
    {
        $subscription = DB::transaction(function () use ($subscriber): CardSubscription {
            $subscription = CardSubscription::where('subscriber_user_id', $subscriber->id)
                ->whereNotIn('status', [CardSubscriptionStatus::Cancelled->value])
                ->lockForUpdate()
                ->latest()
                ->firstOrFail();
            $subscription->status       = CardSubscriptionStatus::Cancelled;
            $subscription->cancelled_at = now();
            $subscription->save();
            return $subscription;
        });

        $this->audit->recordSubscriptionEvent('subscription.cancelled', $subscription, null);

        event(new CardSubscriptionCancelled(
            subscriptionId: (string) $subscription->id,
            cancelledAt:    $subscription->cancelled_at->toIso8601String(),
            cancelledBy:    (string) $subscription->subscriber_user_id,
        ));

        return $subscription;
    }

    /**
     * Return the subscriber's current non-cancelled subscription, or null.
     */
    public function getCurrent(User $subscriber): ?CardSubscription
    {
        return CardSubscription::where('subscriber_user_id', $subscriber->id)
            ->whereNotIn('status', [CardSubscriptionStatus::Cancelled->value])
            ->with('plan')
            ->latest()
            ->first();
    }

    /**
     * Mark a subscription as past-due after a failed billing attempt.
     */
    public function markPastDue(CardSubscription $subscription, string $failureReason): void
    {
        DB::transaction(function () use ($subscription, $failureReason): void {
            $subscription->lockForUpdate();
            $subscription->status               = CardSubscriptionStatus::PastDue;
            $subscription->grace_period_ends_at = now()->addDays(3);
            $subscription->failed_payment_count++;
            $subscription->save();
        });

        $this->audit->recordSubscriptionEvent('subscription.billing_failed', $subscription, null);

        event(new CardSubscriptionPastDue(
            subscriptionId:     (string) $subscription->id,
            failedPaymentCount: $subscription->failed_payment_count,
            gracePeriodEndsAt:  $subscription->grace_period_ends_at->toIso8601String(),
            failureReason:      $failureReason,
        ));
    }

    /**
     * Suspend a subscription and freeze all active cards belonging to it.
     */
    public function suspend(CardSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $subscription->lockForUpdate();
            $subscription->status       = CardSubscriptionStatus::Suspended;
            $subscription->suspended_at = now();
            $subscription->save();

            $subscription->cards()
                ->where('status', 'active')
                ->update(['status' => 'suspended']);
        });

        $this->audit->recordSubscriptionEvent('subscription.suspended', $subscription, null);

        event(new CardSubscriptionSuspended(
            subscriptionId: (string) $subscription->id,
            suspendedAt:    $subscription->suspended_at->toIso8601String(),
        ));
    }

    /**
     * Restore a suspended subscription and reactivate cards that were suspended by billing.
     */
    public function restore(CardSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $subscription->lockForUpdate();
            $subscription->status               = CardSubscriptionStatus::Active;
            $subscription->failed_payment_count = 0;
            $subscription->grace_period_ends_at = null;
            $subscription->save();

            // Only restore cards suspended by the billing suspension, not those
            // frozen by the user or admin.
            $subscription->cards()
                ->where('status', 'suspended')
                ->update(['status' => 'active']);
        });

        $this->audit->recordSubscriptionEvent('subscription.restored', $subscription, null);

        event(new CardSubscriptionRestored(
            subscriptionId: (string) $subscription->id,
            restoredAt:     now()->toIso8601String(),
        ));
    }

    /**
     * Terminate an unpaid subscription and cancel all associated cards.
     */
    public function terminateUnpaid(CardSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $subscription->lockForUpdate();
            $subscription->status       = CardSubscriptionStatus::Cancelled;
            $subscription->cancelled_at = now();
            $subscription->save();

            $subscription->cards()
                ->whereNotIn('status', ['cancelled'])
                ->update(['status' => 'cancelled']);
        });

        $this->audit->recordSubscriptionEvent('subscription.terminated_unpaid', $subscription, null);

        event(new CardSubscriptionCancelled(
            subscriptionId: (string) $subscription->id,
            cancelledAt:    $subscription->cancelled_at->toIso8601String(),
            cancelledBy:    'system',
        ));
    }
}
