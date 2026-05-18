<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Events\Broadcast;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes subscription state changes to the mobile app for instant refetch.
 *
 * Channel: private-user.{userId}.cards (see routes/channels.php).
 */
class CardSubscriptionStateUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $subscriptionId,
        public readonly string $status,
        public readonly array $payload = [],
    ) {
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId . '.cards'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'cards.subscription_state_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return array_merge([
            'subscription_id' => $this->subscriptionId,
            'status'          => $this->status,
        ], $this->payload);
    }

    public function broadcastWhen(): bool
    {
        return (bool) config('websocket.enabled', true);
    }

    public function broadcastConnection(): string
    {
        return config('websocket.queue.connection', 'redis');
    }

    public function broadcastQueue(): string
    {
        return config('websocket.queue.name', 'broadcasts');
    }
}
