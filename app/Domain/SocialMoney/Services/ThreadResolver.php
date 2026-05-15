<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Services;

use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use Illuminate\Support\Facades\DB;

class ThreadResolver
{
    /**
     * Find a `direct` thread between two users, or create one (with a
     * subtle "Started from a payment" system preamble) if missing.
     *
     * Returns null only if creation is genuinely impossible (should not happen
     * in normal flow — we return null rather than throw so the caller can
     * silently skip chat-sync on degenerate inputs like userA === userB).
     */
    public function findOrCreateDirect(int $userA, int $userB): ?Thread
    {
        if ($userA === $userB) {
            return null;
        }

        $existingId = $this->findDirectThreadId($userA, $userB);
        if ($existingId !== null) {
            return Thread::find($existingId);
        }

        return DB::transaction(function () use ($userA, $userB): Thread {
            $thread = Thread::create([
                'type'             => 'direct',
                'name'             => null,
                'avatar_url'       => null,
                'created_by'       => $userA,
                'max_participants' => 2,
                'settings'         => [],
            ]);

            $now = now();
            foreach ([$userA, $userB] as $uid) {
                ThreadParticipant::create([
                    'thread_id' => $thread->id,
                    'user_id'   => $uid,
                    'role'      => 'member',
                    'joined_at' => $now,
                ]);
            }

            // Subtle preamble so the thread doesn't appear out of nowhere.
            Message::create([
                'thread_id'       => $thread->id,
                'sender_id'       => $userA,
                'type'            => 'system',
                'text'            => 'Chat started from a payment',
                'payload'         => ['kind' => 'thread_started_from_transaction'],
                'idempotency_key' => "thr:{$thread->id}:created-from-tx",
                'created_at'      => $now,
            ]);

            return $thread;
        });
    }

    private function findDirectThreadId(int $userA, int $userB): ?int
    {
        $id = DB::table('thread_participants as tp1')
            ->join('thread_participants as tp2', 'tp1.thread_id', '=', 'tp2.thread_id')
            ->join('threads', 'threads.id', '=', 'tp1.thread_id')
            ->where('threads.type', 'direct')
            ->where('tp1.user_id', $userA)
            ->where('tp2.user_id', $userB)
            ->whereNull('tp1.left_at')
            ->whereNull('tp2.left_at')
            ->value('tp1.thread_id');

        return $id !== null ? (int) $id : null;
    }
}
