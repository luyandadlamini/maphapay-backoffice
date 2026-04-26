<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Account;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use Illuminate\Database\Eloquent\Factories\Factory;

class MinorAccountLifecycleTransitionFactory extends Factory
{
    protected $model = MinorAccountLifecycleTransition::class;

    public function definition(): array
    {
        return [
            'tenant_id'          => $this->faker->uuid(),
            'minor_account_uuid' => Account::factory(),
            'transition_type'    => $this->faker->randomElement([
                MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE,
                MinorAccountLifecycleTransition::TYPE_ADULT_TRANSITION_REVIEW,
                MinorAccountLifecycleTransition::TYPE_ADULT_TRANSITION_CUTOFF,
                MinorAccountLifecycleTransition::TYPE_GUARDIAN_CONTINUITY,
            ]),
            'state'               => MinorAccountLifecycleTransition::STATE_PENDING,
            'effective_at'        => now(),
            'executed_at'         => null,
            'blocked_reason_code' => null,
            'metadata'            => null,
        ];
    }
}
