<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Events\Broadcast;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class GroupUpdated implements ShouldBroadcastNow
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $recipientId,
        public readonly int $threadId,
        public readonly string $action,
        public readonly int $actorId,
        public readonly string $actorName,
        public readonly ?int $targetUserId = null,
        public readonly ?string $targetUserName = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chat.{$this->recipientId}")];
    }

    public function broadcastAs(): string
    {
        return 'GroupUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'threadId'       => (string) $this->threadId,
            'action'         => $this->action,
            'actorId'        => (string) $this->actorId,
            'actorName'      => $this->actorName,
            'targetUserId'   => $this->targetUserId !== null ? (string) $this->targetUserId : null,
            'targetUserName' => $this->targetUserName,
            'metadata'       => $this->metadata,
        ];
    }
}
