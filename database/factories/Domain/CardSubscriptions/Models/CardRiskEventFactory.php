<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Models\CardRiskEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardRiskEvent>
 */
class CardRiskEventFactory extends Factory
{
    protected $model = CardRiskEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'            => null,
            'user_id'              => User::factory(),
            'card_id'              => null,
            'event_type'           => 'velocity_limit_warning',
            'severity'             => 'low',
            'description'          => $this->faker->sentence(),
            'metadata'             => [],
            'status'               => 'open',
            'assigned_to_admin_id' => null,
            'resolved_at'          => null,
            'resolution_notes'     => null,
        ];
    }
}
