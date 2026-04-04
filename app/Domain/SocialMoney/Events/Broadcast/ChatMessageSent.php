<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Events\Broadcast;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ChatMessageSent implements ShouldBroadcastNow
{
    /**
     * @param array<string, mixed>|null $requestSnapshot
     */
    public function __construct(
        public readonly int $recipientId,
        public readonly int $threadId,
        public readonly string $threadType,
        public readonly int $senderId,
        public readonly string $senderName,
        public readonly int $messageId,
        public readonly string $messageType,
        public readonly string $preview,
        public readonly ?array $requestSnapshot = null,
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
        return 'ChatMessageSent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'threadId'        => (string) $this->threadId,
            'threadType'      => $this->threadType,
            'senderId'        => (string) $this->senderId,
            'senderName'      => $this->senderName,
            'messageId'       => (string) $this->messageId,
            'messageType'     => $this->messageType,
            'preview'         => $this->preview,
            'requestSnapshot' => $this->requestSnapshot,
        ];
    }
}
