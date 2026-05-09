<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\CardSubscriptionBillingAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CardSubscriptionBillingAttempt>
 */
class CardSubscriptionBillingAttemptFactory extends Factory
{
    protected $model = CardSubscriptionBillingAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'             => null,
            'card_subscription_id'  => CardSubscription::factory(),
            'result'                => 'success',
            'failure_reason'        => null,
            'amount'                => '25.00',
            'currency'              => 'SZL',
            'idempotency_key'       => Str::uuid()->toString(),
            'ledger_posting_id'     => null,
            'attempted_at'          => now(),
        ];
    }
}
