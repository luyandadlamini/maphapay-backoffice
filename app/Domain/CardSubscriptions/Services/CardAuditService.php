<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Models\User;
use InvalidArgumentException;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

class CardAuditService
{
    /**
     * Reject metadata that contains digit runs resembling a PAN (PCI scope).
     *
     * @param array<string, mixed> $metadata
     */
    private function assertMetadataHasNoPanLikeDigitRuns(array $metadata): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($metadata));

        foreach ($iterator as $leaf) {
            if (! is_string($leaf)) {
                continue;
            }

            if (preg_match('/\d{13,19}/', $leaf) === 1) {
                throw new InvalidArgumentException(
                    'Card audit metadata must not contain digit sequences resembling a PAN.',
                );
            }
        }
    }

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
        $this->assertMetadataHasNoPanLikeDigitRuns($metadata);

        /** @var CardAuditLog $log */
        $log = CardAuditLog::create([
            'actor_type'   => $actorType,
            'actor_id'     => $actorId,
            'action'       => $action,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'before_state' => $beforeState,
            'after_state'  => $afterState,
            'metadata'     => $metadata,
            'ip_address'   => request()->ip(),
            'user_agent'   => request()->userAgent(),
        ]);

        return $log;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $metadata
     */
    public function recordSubscriptionEvent(string $action, CardSubscription $sub, ?array $before, array $metadata = []): CardAuditLog
    {
        return $this->record(
            actorType:   'user',
            actorId:     (string) ($metadata['actor_id'] ?? $sub->subscriber_user_id),
            action:      $action,
            entityType:  CardSubscription::class,
            entityId:    (string) $sub->id,
            beforeState: $before,
            afterState:  $sub->toArray(),
            metadata:    $metadata,
        );
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $metadata
     */
    public function recordCardEvent(string $action, Card $card, ?array $before, array $metadata = []): CardAuditLog
    {
        return $this->record(
            actorType:   'user',
            actorId:     (string) ($metadata['actor_id'] ?? $card->user_id),
            action:      $action,
            entityType:  Card::class,
            entityId:    (string) $card->id,
            beforeState: $before,
            afterState:  $card->toArray(),
            metadata:    $metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordAdminAction(User $admin, string $action, string $entityType, string $entityId, array $metadata = []): CardAuditLog
    {
        return $this->record(
            actorType:   'admin',
            actorId:     (string) $admin->id,
            action:      $action,
            entityType:  $entityType,
            entityId:    $entityId,
            beforeState: $metadata['before'] ?? null,
            afterState:  $metadata['after'] ?? null,
            metadata:    $metadata,
        );
    }
}
