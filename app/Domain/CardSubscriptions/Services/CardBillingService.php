<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\Account\Models\Account;
use App\Domain\CardSubscriptions\Enums\CardFeeStatus;
use App\Domain\CardSubscriptions\Enums\CardFeeType;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionBillingResult;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Events\CardSubscriptionCancelled;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPastDue;
use App\Domain\CardSubscriptions\Events\CardSubscriptionRestored;
use App\Domain\CardSubscriptions\Events\CardSubscriptionSuspended;
use App\Domain\CardSubscriptions\Models\CardFee;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\CardSubscriptionBillingAttempt;
use App\Domain\CardSubscriptions\ValueObjects\BillingAttemptResult;
use App\Domain\Wallet\Services\WalletService;
use Illuminate\Support\Facades\DB;

class CardBillingService
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {}

    public function chargeInitialPeriod(CardSubscription $subscription): BillingAttemptResult
    {
        $idempotencyKey = sha1("initial:{$subscription->id}");

        // Idempotent replay: if already attempted, return success
        $existing = CardSubscriptionBillingAttempt::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return BillingAttemptResult::success();
        }

        // Load payer account
        $account = Account::where('user_uuid', $subscription->payer->uuid)->first();
        if ($account === null) {
            $result = BillingAttemptResult::failed('PAYER_ACCOUNT_NOT_FOUND');
            CardSubscriptionBillingAttempt::create([
                'card_subscription_id' => $subscription->id,
                'result'               => CardSubscriptionBillingResult::Failed,
                'failure_reason'       => 'PAYER_ACCOUNT_NOT_FOUND',
                'amount'               => number_format(0, 2, '.', ''),
                'currency'             => 'SZL',
                'idempotency_key'      => $idempotencyKey,
                'attempted_at'         => now(),
            ]);
            $this->handleFailedPayment($subscription, $result);

            return $result;
        }

        $amountCents = (int) round((float) $subscription->plan->monthly_fee * 100);
        $amountFormatted = number_format($amountCents / 100, 2, '.', '');

        // Check balance
        if (! $account->hasSufficientBalance('SZL', $amountCents)) {
            $result = BillingAttemptResult::failed('INSUFFICIENT_FUNDS', $amountCents);
            CardSubscriptionBillingAttempt::create([
                'card_subscription_id' => $subscription->id,
                'result'               => CardSubscriptionBillingResult::Failed,
                'failure_reason'       => 'INSUFFICIENT_FUNDS',
                'amount'               => $amountFormatted,
                'currency'             => 'SZL',
                'idempotency_key'      => $idempotencyKey,
                'attempted_at'         => now(),
            ]);
            $this->handleFailedPayment($subscription, $result);

            return $result;
        }

        // Withdraw funds
        $this->wallets->withdraw($account->uuid, 'SZL', (string) $amountCents);

        // Persist successful billing attempt
        CardSubscriptionBillingAttempt::create([
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Success,
            'amount'               => $amountFormatted,
            'currency'             => 'SZL',
            'idempotency_key'      => $idempotencyKey,
            'attempted_at'         => now(),
        ]);

        // Persist fee record
        CardFee::create([
            'user_id'             => $subscription->payer_user_id,
            'related_entity_id'   => $subscription->id,
            'related_entity_type' => CardSubscription::class,
            'fee_type'            => CardFeeType::Subscription,
            'amount'              => $amountFormatted,
            'currency'            => 'SZL',
            'status'              => CardFeeStatus::Charged,
            'charged_at'          => now(),
        ]);

        return BillingAttemptResult::success(null, $amountCents, 'SZL');
    }

    public function billRenewal(CardSubscription $subscription): BillingAttemptResult
    {
        $idempotencyKey = sha1("renewal:{$subscription->id}:{$subscription->next_billing_date->toIso8601String()}");

        // Idempotent replay: if already attempted, return success
        $existing = CardSubscriptionBillingAttempt::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return BillingAttemptResult::success();
        }

        // Load payer account
        $account = Account::where('user_uuid', $subscription->payer->uuid)->first();
        if ($account === null) {
            $result = BillingAttemptResult::failed('PAYER_ACCOUNT_NOT_FOUND');
            CardSubscriptionBillingAttempt::create([
                'card_subscription_id' => $subscription->id,
                'result'               => CardSubscriptionBillingResult::Failed,
                'failure_reason'       => 'PAYER_ACCOUNT_NOT_FOUND',
                'amount'               => number_format(0, 2, '.', ''),
                'currency'             => 'SZL',
                'idempotency_key'      => $idempotencyKey,
                'attempted_at'         => now(),
            ]);
            $this->handleFailedPayment($subscription, $result);

            return $result;
        }

        $amountCents = (int) round((float) $subscription->plan->monthly_fee * 100);
        $amountFormatted = number_format($amountCents / 100, 2, '.', '');

        // Check balance
        if (! $account->hasSufficientBalance('SZL', $amountCents)) {
            $result = BillingAttemptResult::failed('INSUFFICIENT_FUNDS', $amountCents);
            CardSubscriptionBillingAttempt::create([
                'card_subscription_id' => $subscription->id,
                'result'               => CardSubscriptionBillingResult::Failed,
                'failure_reason'       => 'INSUFFICIENT_FUNDS',
                'amount'               => $amountFormatted,
                'currency'             => 'SZL',
                'idempotency_key'      => $idempotencyKey,
                'attempted_at'         => now(),
            ]);
            $this->handleFailedPayment($subscription, $result);

            return $result;
        }

        // Withdraw funds
        $this->wallets->withdraw($account->uuid, 'SZL', (string) $amountCents);

        // Persist successful billing attempt
        CardSubscriptionBillingAttempt::create([
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Success,
            'amount'               => $amountFormatted,
            'currency'             => 'SZL',
            'idempotency_key'      => $idempotencyKey,
            'attempted_at'         => now(),
        ]);

        // Persist fee record
        CardFee::create([
            'user_id'             => $subscription->payer_user_id,
            'related_entity_id'   => $subscription->id,
            'related_entity_type' => CardSubscription::class,
            'fee_type'            => CardFeeType::Subscription,
            'amount'              => $amountFormatted,
            'currency'            => 'SZL',
            'status'              => CardFeeStatus::Charged,
            'charged_at'          => now(),
        ]);

        $result = BillingAttemptResult::success(null, $amountCents, 'SZL');
        $this->handleSuccessfulPayment($subscription, $result);

        return $result;
    }

    public function retryFailedPayment(CardSubscription $subscription): BillingAttemptResult
    {
        $idempotencyKey = sha1("retry:{$subscription->id}:" . now()->format('Y-m-d'));

        // Idempotent replay: if already attempted today, return success
        $existing = CardSubscriptionBillingAttempt::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return BillingAttemptResult::success();
        }

        // Load payer account
        $account = Account::where('user_uuid', $subscription->payer->uuid)->first();
        if ($account === null) {
            $result = BillingAttemptResult::failed('PAYER_ACCOUNT_NOT_FOUND');
            CardSubscriptionBillingAttempt::create([
                'card_subscription_id' => $subscription->id,
                'result'               => CardSubscriptionBillingResult::Failed,
                'failure_reason'       => 'PAYER_ACCOUNT_NOT_FOUND',
                'amount'               => number_format(0, 2, '.', ''),
                'currency'             => 'SZL',
                'idempotency_key'      => $idempotencyKey,
                'attempted_at'         => now(),
            ]);
            $this->handleFailedPayment($subscription, $result);

            return $result;
        }

        $amountCents = (int) round((float) $subscription->plan->monthly_fee * 100);
        $amountFormatted = number_format($amountCents / 100, 2, '.', '');

        // Check balance
        if (! $account->hasSufficientBalance('SZL', $amountCents)) {
            $result = BillingAttemptResult::failed('INSUFFICIENT_FUNDS', $amountCents);
            CardSubscriptionBillingAttempt::create([
                'card_subscription_id' => $subscription->id,
                'result'               => CardSubscriptionBillingResult::Failed,
                'failure_reason'       => 'INSUFFICIENT_FUNDS',
                'amount'               => $amountFormatted,
                'currency'             => 'SZL',
                'idempotency_key'      => $idempotencyKey,
                'attempted_at'         => now(),
            ]);
            $this->handleFailedPayment($subscription, $result);

            return $result;
        }

        // Withdraw funds
        $this->wallets->withdraw($account->uuid, 'SZL', (string) $amountCents);

        // Persist successful billing attempt
        CardSubscriptionBillingAttempt::create([
            'card_subscription_id' => $subscription->id,
            'result'               => CardSubscriptionBillingResult::Success,
            'amount'               => $amountFormatted,
            'currency'             => 'SZL',
            'idempotency_key'      => $idempotencyKey,
            'attempted_at'         => now(),
        ]);

        // Persist fee record
        CardFee::create([
            'user_id'             => $subscription->payer_user_id,
            'related_entity_id'   => $subscription->id,
            'related_entity_type' => CardSubscription::class,
            'fee_type'            => CardFeeType::Subscription,
            'amount'              => $amountFormatted,
            'currency'            => 'SZL',
            'status'              => CardFeeStatus::Charged,
            'charged_at'          => now(),
        ]);

        $result = BillingAttemptResult::success(null, $amountCents, 'SZL');
        $this->handleSuccessfulPayment($subscription, $result);

        return $result;
    }

    public function handleSuccessfulPayment(CardSubscription $subscription, BillingAttemptResult $result): void
    {
        $wasPastDueOrSuspended = false;
        $dispatchRestored = false;

        DB::transaction(function () use ($subscription, &$wasPastDueOrSuspended, &$dispatchRestored): void {
            $locked = CardSubscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();

            $wasPastDueOrSuspended = in_array($locked->status, [
                CardSubscriptionStatus::PastDue,
                CardSubscriptionStatus::Suspended,
            ], true);

            // Roll the period forward
            $locked->current_period_start = $locked->next_billing_date;
            $locked->current_period_end   = $locked->next_billing_date->addMonth();
            $locked->next_billing_date    = $locked->next_billing_date->addMonth();

            $locked->failed_payment_count = 0;
            $locked->grace_period_ends_at = null;

            if ($locked->status === CardSubscriptionStatus::PastDue) {
                $locked->status = CardSubscriptionStatus::Active;
            } elseif ($locked->status === CardSubscriptionStatus::Suspended) {
                $locked->status = CardSubscriptionStatus::Active;
                $locked->cards()->where('status', 'suspended')->update(['status' => 'active']);
            }

            $locked->save();

            $dispatchRestored = $wasPastDueOrSuspended;
        });

        if ($dispatchRestored) {
            event(new CardSubscriptionRestored(
                subscriptionId: (string) $subscription->id,
                restoredAt: now()->toIso8601String(),
            ));
        }
    }

    public function handleFailedPayment(CardSubscription $subscription, BillingAttemptResult $result): void
    {
        $eventToDispatch = null;
        $eventPayload    = [];

        DB::transaction(function () use ($subscription, $result, &$eventToDispatch, &$eventPayload): void {
            $locked = CardSubscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();

            $locked->failed_payment_count++;

            if ($locked->status === CardSubscriptionStatus::Active) {
                $locked->status              = CardSubscriptionStatus::PastDue;
                $locked->grace_period_ends_at = now()->addDays(3);
                $locked->save();

                $eventToDispatch = 'past_due';
                $eventPayload    = [
                    'subscriptionId'    => (string) $subscription->id,
                    'failedPaymentCount' => $locked->failed_payment_count,
                    'gracePeriodEndsAt' => $locked->grace_period_ends_at->toIso8601String(),
                    'failureReason'     => $result->reason ?? 'UNKNOWN',
                ];
            } elseif (
                $locked->status === CardSubscriptionStatus::PastDue
                && $locked->grace_period_ends_at !== null
                && $locked->grace_period_ends_at->isPast()
            ) {
                $locked->status       = CardSubscriptionStatus::Suspended;
                $locked->suspended_at = now();
                $locked->save();

                $locked->cards()->where('status', 'active')->update(['status' => 'suspended']);

                $eventToDispatch = 'suspended';
                $eventPayload    = [
                    'subscriptionId' => (string) $subscription->id,
                    'suspendedAt'    => $locked->suspended_at->toIso8601String(),
                ];
            } elseif (
                $locked->status === CardSubscriptionStatus::Suspended
                && $locked->suspended_at !== null
                && $locked->suspended_at->diffInDays(now()) >= 14
            ) {
                $locked->status       = CardSubscriptionStatus::Cancelled;
                $locked->cancelled_at = now();
                $locked->save();

                $locked->cards()->whereNotIn('status', ['cancelled'])->update(['status' => 'cancelled']);

                $eventToDispatch = 'cancelled';
                $eventPayload    = [
                    'subscriptionId' => (string) $subscription->id,
                    'cancelledAt'    => $locked->cancelled_at->toIso8601String(),
                    'cancelledBy'    => 'system',
                ];
            } else {
                // Increment already applied; no FSM transition
                $locked->save();
            }
        });

        // Dispatch events outside transaction
        match ($eventToDispatch) {
            'past_due'  => event(new CardSubscriptionPastDue(
                subscriptionId:    $eventPayload['subscriptionId'],
                failedPaymentCount: $eventPayload['failedPaymentCount'],
                gracePeriodEndsAt: $eventPayload['gracePeriodEndsAt'],
                failureReason:     $eventPayload['failureReason'],
            )),
            'suspended' => event(new CardSubscriptionSuspended(
                subscriptionId: $eventPayload['subscriptionId'],
                suspendedAt:    $eventPayload['suspendedAt'],
            )),
            'cancelled' => event(new CardSubscriptionCancelled(
                subscriptionId: $eventPayload['subscriptionId'],
                cancelledAt:    $eventPayload['cancelledAt'],
                cancelledBy:    $eventPayload['cancelledBy'],
            )),
            default     => null,
        };
    }
}
