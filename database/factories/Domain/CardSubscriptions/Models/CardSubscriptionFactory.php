<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardSubscription>
 */
class CardSubscriptionFactory extends Factory
{
    protected $model = CardSubscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now();

        return [
            'tenant_id'             => null,
            'subscriber_user_id'    => User::factory(),
            'payer_user_id'         => User::factory(),
            'card_plan_id'          => CardPlan::factory(),
            'status'                => 'active',
            'current_period_start'  => $periodStart,
            'current_period_end'    => $periodStart->copy()->addMonth(),
            'next_billing_date'     => $periodStart->copy()->addMonth(),
            'failed_payment_count'  => 0,
            'grace_period_ends_at'  => null,
            'suspended_at'          => null,
            'cancelled_at'          => null,
            'is_minor_subscription' => false,
            'guardian_user_id'      => null,
            'minor_account_uuid'    => null,
            'minor_card_request_id' => null,
        ];
    }
}
