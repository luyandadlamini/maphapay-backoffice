<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Domain\SocialMoney\Events\Broadcast\SocialTypingUpdated;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function send(Request $request, int $threadId): JsonResponse
    {
        $request->validate([
            'type'           => 'required|in:text,bill_split,payment,request',
            'text'           => 'required_if:type,text|nullable|string|max:4000',
            'payload'        => 'nullable|array',
            'idempotencyKey' => 'nullable|string|max:36',
        ]);

        $thread = Thread::findOrFail($threadId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $this->ensureActiveMember($thread, $userId);

        $idempotencyKey = $request->input('idempotencyKey');
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $existing = Message::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return response()->json([
                    'status' => 'success',
                    'data'   => ['messageId' => (string) $existing->id],
                ]);
            }
        }

        $message = Message::create([
            'thread_id'       => $thread->id,
            'sender_id'       => $userId,
            'type'            => $request->input('type'),
            'text'            => $request->input('text'),
            'payload'         => $request->input('payload'),
            'idempotency_key' => $idempotencyKey,
            'created_at'      => now(),
        ]);

        $this->broadcastMessage($thread, $message, $request);

        return response()->json([
            'status' => 'success',
            'data'   => ['messageId' => (string) $message->id],
        ]);
    }

    public function index(Request $request, int $threadId): JsonResponse
    {
        $thread = Thread::findOrFail($threadId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $this->ensureActiveMember($thread, $userId);

        $limit = min((int) $request->query('limit', 30), 50);
        $cursor = $request->query('cursor');

        $query = Message::where('thread_id', $thread->id)
            ->with('sender:id,name')
            ->orderByDesc('id');

        $participant = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->first();

        if ($participant !== null && $participant->joined_at !== null) {
            $query->where('created_at', '>=', $participant->joined_at);
        }

        if (is_string($cursor) && $cursor !== '') {
            $query->where('id', '<', (int) $cursor);
        }

        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        if ($hasMore) {
            $messages = $messages->take($limit);
        }

        $formatted = $messages->map(fn (Message $message) => [
            'id'         => (string) $message->id,
            'threadId'   => (string) $message->thread_id,
            'senderId'   => (string) $message->sender_id,
            'senderName' => $message->sender !== null ? $message->sender->name : 'User',
            'type'       => $message->type,
            'text'       => $message->text,
            'payload'    => $message->payload,
            'status'     => $message->status,
            'createdAt'  => $message->created_at->toISOString(),
        ])->values()->all();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'messages'   => $formatted,
                'nextCursor' => $hasMore ? (string) $messages->last()?->id : null,
            ],
        ]);
    }

    public function markRead(Request $request, int $threadId): JsonResponse
    {
        $request->validate(['lastReadMessageId' => 'required|integer']);

        $thread = Thread::findOrFail($threadId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $this->ensureActiveMember($thread, $userId);

        MessageRead::upsert(
            [[
                'thread_id'            => $thread->id,
                'user_id'              => $userId,
                'last_read_message_id' => (int) $request->input('lastReadMessageId'),
                'read_at'              => now(),
            ]],
            ['thread_id', 'user_id'],
            ['last_read_message_id', 'read_at'],
        );

        return response()->json(['status' => 'success']);
    }

    public function typing(Request $request, int $threadId): JsonResponse
    {
        $request->validate(['isTyping' => 'required|boolean']);

        $thread = Thread::findOrFail($threadId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $this->ensureActiveMember($thread, $userId);

        $isTyping = (bool) $request->input('isTyping');
        $actorName = $user->name;
        $expiresAt = $isTyping
            ? now()->addSeconds(config('social.typing_indicator_ttl_seconds', 3))->toIso8601String()
            : now()->toIso8601String();

        $recipientIds = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id');

        foreach ($recipientIds as $recipientId) {
            event(new SocialTypingUpdated(
                recipientId: (int) $recipientId,
                threadId: $thread->id,
                actorUserId: $userId,
                actorDisplayName: $actorName,
                isTyping: $isTyping,
                expiresAt: $expiresAt,
            ));
        }

        return response()->json(['status' => 'success', 'remark' => 'social_typing']);
    }

    private function ensureActiveMember(Thread $thread, int $userId): void
    {
        $isMember = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            abort(403, 'You are not a member of this thread');
        }
    }

    private function broadcastMessage(Thread $thread, Message $message, Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $senderName = $user->name;
        $preview = $message->text ?? $message->type;

        $recipientIds = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id');

        foreach ($recipientIds as $recipientId) {
            event(new ChatMessageSent(
                recipientId: (int) $recipientId,
                threadId: $thread->id,
                threadType: $thread->type,
                senderId: $userId,
                senderName: $senderName,
                messageId: $message->id,
                messageType: $message->type,
                preview: $preview,
            ));
        }
    }
}
