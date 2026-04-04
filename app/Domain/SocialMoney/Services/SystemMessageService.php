<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Services;

use App\Models\Message;
use App\Models\Thread;

class SystemMessageService
{
    public function memberAdded(Thread $thread, int $actorId, int $targetUserId, string $actorName, string $targetName): Message
    {
        return $this->createSystemMessage($thread, $actorId, "{$actorName} added {$targetName}", [
            'action'       => 'member_added',
            'targetUserId' => $targetUserId,
        ]);
    }

    public function memberRemoved(Thread $thread, int $actorId, int $targetUserId, string $actorName, string $targetName): Message
    {
        return $this->createSystemMessage($thread, $actorId, "{$actorName} removed {$targetName}", [
            'action'       => 'member_removed',
            'targetUserId' => $targetUserId,
        ]);
    }

    public function memberLeft(Thread $thread, int $actorId, string $actorName): Message
    {
        return $this->createSystemMessage($thread, $actorId, "{$actorName} left the group", [
            'action' => 'member_left',
        ]);
    }

    public function groupRenamed(Thread $thread, int $actorId, string $actorName, string $newName): Message
    {
        return $this->createSystemMessage($thread, $actorId, "{$actorName} renamed the group to \"{$newName}\"", [
            'action'  => 'renamed',
            'newName' => $newName,
        ]);
    }

    public function roleChanged(Thread $thread, int $actorId, string $actorName, string $targetName, string $newRole): Message
    {
        $label = $newRole === 'admin' ? 'an admin' : 'a member';

        return $this->createSystemMessage($thread, $actorId, "{$actorName} made {$targetName} {$label}", [
            'action'  => 'role_changed',
            'newRole' => $newRole,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createSystemMessage(Thread $thread, int $actorId, string $text, array $payload): Message
    {
        return Message::create([
            'thread_id'  => $thread->id,
            'sender_id'  => $actorId,
            'type'       => 'system',
            'text'       => $text,
            'payload'    => $payload,
            'created_at' => now(),
        ]);
    }
}
