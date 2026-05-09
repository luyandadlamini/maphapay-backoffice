<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CardDispute>
 */
class CardDisputeFactory extends Factory
{
    protected $model = CardDispute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'                  => null,
            'user_id'                    => User::factory(),
            'card_transaction_id'        => Str::uuid()->toString(),
            'reason'                     => 'unrecognised',
            'status'                     => 'submitted',
            'user_description'           => $this->faker->sentence(),
            'evidence'                   => [],
            'disputed_amount'            => '25.00',
            'currency'                   => 'SZL',
            'processor_dispute_id'       => null,
            'submitted_at'               => now(),
            'processor_acknowledged_at'  => null,
            'resolved_at'                => null,
            'resolution_notes'           => null,
        ];
    }
}
