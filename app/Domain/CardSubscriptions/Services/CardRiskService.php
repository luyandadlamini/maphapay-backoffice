<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\ValueObjects\AuthorizationRequest;
use App\Domain\CardSubscriptions\Enums\CardRiskSeverity;
use App\Domain\CardSubscriptions\Models\CardRiskEvent;
use App\Domain\CardSubscriptions\ValueObjects\RiskDecision;
use App\Models\User;

class CardRiskService
{
    public function evaluateCardCreation(User $user): RiskDecision
    {
        throw new \LogicException('not implemented');
    }

    public function evaluateAuthorization(Card $card, AuthorizationRequest $req): RiskDecision
    {
        throw new \LogicException('not implemented');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordEvent(User $user, ?Card $card, string $eventType, CardRiskSeverity $severity, array $metadata = []): CardRiskEvent
    {
        throw new \LogicException('not implemented');
    }

    public function suspendCardsForUser(User $user, string $reason): void
    {
        throw new \LogicException('not implemented');
    }
}
