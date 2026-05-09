<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Models\User;

class CardAuditService
{
    /**
     * @param array<string, mixed>|null $beforeState
     * @param array<string, mixed>|null $afterState
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $actorType,
        ?string $actorId,
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $beforeState,
        ?array $afterState,
        array $metadata = [],
    ): CardAuditLog {
        throw new \LogicException('not implemented');
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $metadata
     */
    public function recordSubscriptionEvent(string $action, CardSubscription $sub, ?array $before, array $metadata = []): CardAuditLog
    {
        throw new \LogicException('not implemented');
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $metadata
     */
    public function recordCardEvent(string $action, Card $card, ?array $before, array $metadata = []): CardAuditLog
    {
        throw new \LogicException('not implemented');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordAdminAction(User $admin, string $action, string $entityType, string $entityId, array $metadata = []): CardAuditLog
    {
        throw new \LogicException('not implemented');
    }
}
