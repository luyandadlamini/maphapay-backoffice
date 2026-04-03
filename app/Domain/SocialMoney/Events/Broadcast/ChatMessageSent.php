<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param array<string, mixed> $message
     */
    public function __construct(
        public readonly int $recipientId,
        public readonly int $senderId,
        public readonly array $message,
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
            'message' => $this->message,
            'senderId' => (string) $this->senderId,
        ];
    }
}
