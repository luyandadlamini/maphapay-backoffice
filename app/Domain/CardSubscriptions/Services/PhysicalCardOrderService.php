<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\PhysicalCardOrderStatus;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\CardSubscriptions\ValueObjects\ActivateInput;
use App\Domain\CardSubscriptions\ValueObjects\RequestPhysicalCardInput;
use App\Models\User;

class PhysicalCardOrderService
{
    public function request(User $user, CardSubscription $subscription, RequestPhysicalCardInput $input): PhysicalCardOrder
    {
        return PhysicalCardOrder::create([
            'user_id'              => $user->id,
            'card_subscription_id' => $subscription->id,
            'card_id'              => $subscription->card_id,
            'order_status'         => PhysicalCardOrderStatus::Requested,
            'delivery_method'      => $input->deliveryMethod,
            'delivery_address'     => $input->deliveryAddress?->toArray(),
            'collection_point_id'  => $input->collectionPointId,
            'requested_at'         => now(),
        ]);
    }

    public function activate(User $user, PhysicalCardOrder $order, ActivateInput $input): Card
    {
        $order->update([
            'order_status' => PhysicalCardOrderStatus::Activated,
            'activated_at' => now(),
        ]);

        return $order->card;
    }

    public function cancel(User $user, PhysicalCardOrder $order, string $reason): PhysicalCardOrder
    {
        $order->update([
            'order_status'        => PhysicalCardOrderStatus::Cancelled,
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
        ]);

        return $order;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transition(PhysicalCardOrder $order, string $newStatus, array $metadata = []): PhysicalCardOrder
    {
        $status = PhysicalCardOrderStatus::from($newStatus);

        $update = [
            'order_status' => $status,
        ];

        $timestampMap = [
            PhysicalCardOrderStatus::Paid->value               => 'paid_at',
            PhysicalCardOrderStatus::Approved->value           => 'approved_at',
            PhysicalCardOrderStatus::Production->value         => 'production_at',
            PhysicalCardOrderStatus::Dispatched->value         => 'dispatched_at',
            PhysicalCardOrderStatus::ReadyForCollection->value => 'ready_for_collection_at',
            PhysicalCardOrderStatus::Delivered->value          => 'delivered_at',
            PhysicalCardOrderStatus::Activated->value          => 'activated_at',
            PhysicalCardOrderStatus::Cancelled->value          => 'cancelled_at',
        ];

        if (isset($timestampMap[$status->value])) {
            $update[$timestampMap[$status->value]] = now();
        }

        $order->update($update);

        return $order;
    }
}
