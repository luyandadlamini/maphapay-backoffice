<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\ValueObjects\CardLimitSet;
use App\Domain\CardSubscriptions\ValueObjects\CreateVirtualCardInput;
use App\Models\User;

class MinorCardSubscriptionService
{
    public function requestSubscribe(User $minor, string $planCode): MinorCardRequest
    {
        throw new \LogicException('not implemented');
    }

    public function requestPlanChange(User $minor, string $newPlanCode): MinorCardRequest
    {
        throw new \LogicException('not implemented');
    }

    public function requestCardCreation(User $minor, CreateVirtualCardInput $input): MinorCardRequest
    {
        throw new \LogicException('not implemented');
    }

    public function requestLimitChange(User $minor, Card $card, CardLimitSet $newLimits): MinorCardRequest
    {
        throw new \LogicException('not implemented');
    }

    public function approve(User $guardian, MinorCardRequest $request, ?string $note = null): void
    {
        throw new \LogicException('not implemented');
    }

    public function deny(User $guardian, MinorCardRequest $request, string $reason): void
    {
        throw new \LogicException('not implemented');
    }
}
