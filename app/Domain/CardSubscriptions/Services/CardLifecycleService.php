<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\ValueObjects\CardControlsInput;
use App\Domain\CardSubscriptions\ValueObjects\CreateVirtualCardInput;
use App\Domain\CardSubscriptions\ValueObjects\ReplacementReason;
use App\Models\User;

class CardLifecycleService
{
    public function createVirtualCard(User $user, CardSubscription $subscription, CreateVirtualCardInput $input): Card
    {
        throw new \LogicException('not implemented');
    }

    public function freezeCard(User $actor, Card $card, string $reason): Card
    {
        throw new \LogicException('not implemented');
    }

    public function unfreezeCard(User $actor, Card $card): Card
    {
        throw new \LogicException('not implemented');
    }

    public function cancelCard(User $actor, Card $card, string $reason): Card
    {
        throw new \LogicException('not implemented');
    }

    public function replaceCard(User $actor, Card $card, ReplacementReason $reason): Card
    {
        throw new \LogicException('not implemented');
    }

    public function updateControls(User $actor, Card $card, CardControlsInput $controls): Card
    {
        throw new \LogicException('not implemented');
    }

    public function adminFreeze(User $admin, Card $card, string $reason): Card
    {
        throw new \LogicException('not implemented');
    }

    public function adminUnfreeze(User $admin, Card $card, string $reason): Card
    {
        throw new \LogicException('not implemented');
    }
}
