<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\CardSubscriptions\ValueObjects\ActivateInput;
use App\Domain\CardSubscriptions\ValueObjects\RequestPhysicalCardInput;
use App\Models\User;

class PhysicalCardOrderService
{
    public function request(User $user, CardSubscription $subscription, RequestPhysicalCardInput $input): PhysicalCardOrder
    {
        throw new \LogicException('not implemented');
    }

    public function activate(User $user, PhysicalCardOrder $order, ActivateInput $input): Card
    {
        throw new \LogicException('not implemented');
    }

    public function cancel(User $user, PhysicalCardOrder $order, string $reason): PhysicalCardOrder
    {
        throw new \LogicException('not implemented');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transition(PhysicalCardOrder $order, string $newStatus, array $metadata = []): PhysicalCardOrder
    {
        throw new \LogicException('not implemented');
    }
}
