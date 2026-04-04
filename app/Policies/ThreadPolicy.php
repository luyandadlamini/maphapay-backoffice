<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;

class ThreadPolicy
{
    /**
     * User is an active participant of the thread.
     */
    public function view(User $user, Thread $thread): bool
    {
        return $this->isActiveMember($user, $thread);
    }

    /**
     * User can send messages / create financial actions.
     */
    public function sendMessage(User $user, Thread $thread): bool
    {
        return $this->isActiveMember($user, $thread);
    }

    /**
     * User is an admin of the thread (group only).
     */
    public function manage(User $user, Thread $thread): bool
    {
        if ($thread->isDirect()) {
            return false;
        }

        return ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->where('role', 'admin')
            ->exists();
    }

    private function isActiveMember(User $user, Thread $thread): bool
    {
        return ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();
    }
}
