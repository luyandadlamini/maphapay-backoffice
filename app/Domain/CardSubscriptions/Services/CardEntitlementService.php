<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\AuthorizationRequest;
use App\Domain\CardSubscriptions\ValueObjects\EntitlementDecision;
use App\Models\User;

class CardEntitlementService
{
    public function canUseFeature(User $user, string $featureCode): EntitlementDecision
    {
        throw new \LogicException('not implemented');
    }

    public function canSubscribeToPlan(User $user, string $planCode): EntitlementDecision
    {
        throw new \LogicException('not implemented');
    }

    public function canCreateVirtualCard(User $user): EntitlementDecision
    {
        throw new \LogicException('not implemented');
    }

    public function canRequestPhysicalCard(User $user): EntitlementDecision
    {
        throw new \LogicException('not implemented');
    }

    public function canAuthorize(Card $card, AuthorizationRequest $authorization): EntitlementDecision
    {
        throw new \LogicException('not implemented');
    }

    public function canRevealCard(User $user, Card $card): EntitlementDecision
    {
        throw new \LogicException('not implemented');
    }
}
