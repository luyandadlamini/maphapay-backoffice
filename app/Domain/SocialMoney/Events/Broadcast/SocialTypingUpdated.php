<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Events\Broadcast;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class SocialTypingUpdated implements ShouldBroadcastNow
{
    public function __construct(
        public readonly int $recipientId,
        public readonly int $threadId,
        public readonly int $actorUserId,
        public readonly string $actorDisplayName,
        public readonly bool $isTyping,
        public readonly string $expiresAt,
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
        return 'TypingUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'threadId'         => (string) $this->threadId,
            'actorUserId'      => (string) $this->actorUserId,
            'actorDisplayName' => $this->actorDisplayName,
            'isTyping'         => $this->isTyping,
            'expiresAt'        => $this->expiresAt,
        ];
    }
}
