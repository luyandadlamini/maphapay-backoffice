<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\ValueObjects\BillingAttemptResult;

class CardBillingService
{
    public function chargeInitialPeriod(CardSubscription $subscription): BillingAttemptResult
    {
        throw new \LogicException('not implemented');
    }

    public function billRenewal(CardSubscription $subscription): BillingAttemptResult
    {
        throw new \LogicException('not implemented');
    }

    public function retryFailedPayment(CardSubscription $subscription): BillingAttemptResult
    {
        throw new \LogicException('not implemented');
    }

    public function handleSuccessfulPayment(CardSubscription $subscription, BillingAttemptResult $result): void
    {
        throw new \LogicException('not implemented');
    }

    public function handleFailedPayment(CardSubscription $subscription, BillingAttemptResult $result): void
    {
        throw new \LogicException('not implemented');
    }
}
