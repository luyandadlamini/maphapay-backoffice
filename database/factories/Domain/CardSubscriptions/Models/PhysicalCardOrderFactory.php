<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhysicalCardOrder>
 */
class PhysicalCardOrderFactory extends Factory
{
    protected $model = PhysicalCardOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'                => null,
            'user_id'                  => User::factory(),
            'card_subscription_id'     => CardSubscription::factory(),
            'card_id'                  => null,
            'order_status'             => 'requested',
            'delivery_method'          => 'branch_collection',
            'delivery_address'         => null,
            'collection_point_id'      => null,
            'issuance_fee'             => '120.00',
            'delivery_fee'             => '0.00',
            'tracking_reference'       => null,
            'requested_at'             => now(),
            'paid_at'                  => null,
            'approved_at'              => null,
            'production_at'            => null,
            'dispatched_at'            => null,
            'ready_for_collection_at'  => null,
            'delivered_at'             => null,
            'activated_at'             => null,
            'cancelled_at'             => null,
            'cancellation_reason'      => null,
        ];
    }
}
