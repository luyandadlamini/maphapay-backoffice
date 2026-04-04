<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SocialMoney;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThreadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $userId = (int) $user->getAuthIdentifier();

        $threadIds = ThreadParticipant::where('user_id', $userId)
            ->whereNull('left_at')
            ->pluck('thread_id');

        $threads = Thread::whereIn('id', $threadIds)
            ->with(['activeParticipants.user:id,name', 'latestMessage.sender:id,name'])
            ->get();

        $reads = MessageRead::where('user_id', $userId)
            ->whereIn('thread_id', $threadIds)
            ->pluck('last_read_message_id', 'thread_id');

        $result = $threads->map(function (Thread $thread) use ($userId, $reads) {
            $lastMessage = $thread->latestMessage;
            $lastText = $lastMessage ? $lastMessage->text ?? '' : '';
            $lastTs = $lastMessage?->created_at?->toISOString() ?? now()->toISOString();

            $lastReadId = $reads->get($thread->id);
            $unreadCount = $lastReadId
                ? Message::where('thread_id', $thread->id)->where('id', '>', $lastReadId)->where('sender_id', '!=', $userId)->count()
                : ($lastMessage ? Message::where('thread_id', $thread->id)->where('sender_id', '!=', $userId)->count() : 0);

            $preview = $lastText;
            if ($thread->isGroup() && $lastMessage && $lastMessage->type !== 'system') {
                $senderName = $lastMessage->sender->name ?? 'Unknown';
                $shortName = explode(' ', $senderName)[0];
                $preview = $lastMessage->sender_id === $userId ? "You: {$lastText}" : "{$shortName}: {$lastText}";
            }

            $pillVariant = 'none';
            $pillLabel = null;
            $hasPendingAction = false;
            if ($lastMessage && $lastMessage->type === 'request') {
                $reqPayload = $lastMessage->payload ?? [];
                $status = $reqPayload['status'] ?? 'pending';
                if ($status === 'pending') {
                    $pillVariant = $lastMessage->sender_id === $userId ? 'pending' : 'incoming';
                    $hasPendingAction = $lastMessage->sender_id !== $userId;
                }
            } elseif ($lastMessage && $lastMessage->type === 'bill_split') {
                $pillVariant = 'split';
            }

            $base = [
                'threadId'         => (string) $thread->id,
                'type'             => $thread->type,
                'lastMessage'      => $preview,
                'lastTimestamp'    => $lastTs,
                'unreadCount'      => $unreadCount,
                'rowSubtitle'      => $preview,
                'pillVariant'      => $pillVariant,
                'pillLabel'        => $pillLabel,
                'hasPendingAction' => $hasPendingAction,
            ];

            if ($thread->isDirect()) {
                $peer = $thread->activeParticipants->firstWhere('user_id', '!=', $userId);
                $peerUser = $peer?->user;
                $name = $peerUser->name ?? 'User';
                $parts = preg_split('/\s+/', trim($name)) ?: [];
                $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[1] ?? '', 0, 1));

                $base['friendId'] = $peerUser ? (string) $peerUser->id : null;
                $base['friendName'] = $name;
                $base['avatarInitials'] = $initials ?: 'U';
                $base['avatarColor'] = '#5B8DEF';
            } else {
                $base['name'] = $thread->name;
                $base['avatarUrl'] = $thread->avatar_url;
                $base['participantCount'] = $thread->activeParticipants->count();
                $base['participants'] = $thread->activeParticipants->map(fn ($p) => [
                    'userId' => (string) $p->user_id,
                    'name'   => $p->user->name ?? 'User',
                    'role'   => $p->role,
                ])->values()->all();
            }

            return $base;
        })
            ->sortByDesc('lastTimestamp')
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'data'   => ['threads' => $result],
        ]);
    }

    public function createDirect(Request $request): JsonResponse
    {
        $request->validate(['friendId' => 'required|integer']);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $userId = (int) $user->getAuthIdentifier();
        $friendId = (int) $request->input('friendId');

        $isFriend = DB::table('friendships')
            ->where('user_id', $userId)
            ->where('friend_id', $friendId)
            ->where('status', 'accepted')
            ->exists();

        if (! $isFriend) {
            return response()->json(['status' => 'error', 'message' => 'Not friends'], 403);
        }

        $existingThreadId = DB::table('thread_participants as tp1')
            ->join('thread_participants as tp2', 'tp1.thread_id', '=', 'tp2.thread_id')
            ->join('threads', 'threads.id', '=', 'tp1.thread_id')
            ->where('threads.type', 'direct')
            ->where('tp1.user_id', $userId)
            ->where('tp2.user_id', $friendId)
            ->value('tp1.thread_id');

        if ($existingThreadId) {
            $thread = Thread::with('activeParticipants.user:id,name')->findOrFail((int) $existingThreadId);

            return response()->json([
                'status' => 'success',
                'data'   => ['thread' => $this->formatThread($thread), 'isNew' => false],
            ]);
        }

        $thread = $this->createDirectThread($userId, $friendId);

        return response()->json([
            'status' => 'success',
            'data'   => ['thread' => $this->formatThread($thread), 'isNew' => true],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatThread(Thread $thread): array
    {
        return [
            'id'           => (string) $thread->id,
            'type'         => $thread->type,
            'name'         => $thread->name,
            'createdBy'    => (string) $thread->created_by,
            'participants' => $thread->activeParticipants->map(fn ($p) => [
                'userId' => (string) $p->user_id,
                'name'   => $p->user->name ?? 'User',
                'role'   => $p->role,
            ])->values()->all(),
        ];
    }

    private function createDirectThread(int $userId, int $friendId): Thread
    {
        return DB::transaction(function () use ($userId, $friendId): Thread {
            $thread = Thread::create(['type' => 'direct', 'created_by' => $userId]);
            ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $userId, 'role' => 'member', 'joined_at' => now()]);
            ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $friendId, 'role' => 'member', 'joined_at' => now()]);
            $thread->load('activeParticipants.user:id,name');

            return $thread;
        });
    }
}
