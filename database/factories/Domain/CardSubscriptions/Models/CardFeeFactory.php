<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Models\CardFee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardFee>
 */
class CardFeeFactory extends Factory
{
    protected $model = CardFee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'           => null,
            'user_id'             => User::factory(),
            'related_entity_id'   => null,
            'related_entity_type' => null,
            'fee_type'            => 'subscription',
            'amount'              => '25.00',
            'currency'            => 'SZL',
            'status'              => 'pending',
            'ledger_posting_id'   => null,
            'charged_at'          => null,
            'waived_at'           => null,
            'refunded_at'         => null,
            'notes'               => null,
        ];
    }
}
