<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MoneyRequest;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ThreadRequestController extends Controller
{
    private const MONEY_REQUEST_ID_JSON_PATH = '$.moneyRequestId';

    public function store(Request $request, int $threadId): JsonResponse
    {
        $request->validate([
            'amount'          => 'required|numeric|min:0.01',
            'note'            => 'nullable|string|max:2000',
            'targetUserIds'   => 'required|array|min:1',
            'targetUserIds.*' => 'integer',
        ]);

        $thread = Thread::findOrFail($threadId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $this->ensureActiveMember($thread, $userId);

        $targetIds = array_map('intval', $request->input('targetUserIds'));
        $activeCount = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->whereIn('user_id', $targetIds)
            ->count();

        if ($activeCount !== count($targetIds)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'All targets must be active thread members',
            ], 422);
        }

        $messageIds = [];

        DB::transaction(function () use ($request, $thread, $userId, $targetIds, &$messageIds): void {
            foreach ($targetIds as $targetId) {
                $moneyRequest = MoneyRequest::create([
                    'id'                => Str::uuid()->toString(),
                    'requester_user_id' => $userId,
                    'recipient_user_id' => $targetId,
                    'amount'            => $request->input('amount'),
                    'asset_code'        => 'SZL',
                    'note'              => $request->input('note'),
                    'status'            => MoneyRequest::STATUS_PENDING,
                ]);

                $message = Message::create([
                    'thread_id' => $thread->id,
                    'sender_id' => $userId,
                    'type'      => 'request',
                    'text'      => 'Money request',
                    'payload'   => [
                        'moneyRequestId' => $moneyRequest->id,
                        'amount'         => (float) $request->input('amount'),
                        'note'           => $request->input('note'),
                        'status'         => 'pending',
                        'targetUserId'   => (string) $targetId,
                    ],
                    'created_at' => now(),
                ]);

                $messageIds[] = $message->id;
            }
        });

        $senderName = $user->name;
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
                messageId: $messageIds[0] ?? 0,
                messageType: 'request',
                preview: 'Money request',
            ));
        }

        return response()->json([
            'status' => 'success',
            'data'   => ['messageIds' => array_map('strval', $messageIds)],
        ]);
    }

    public function decline(Request $request, string $requestId): JsonResponse
    {
        $moneyRequest = MoneyRequest::findOrFail($requestId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();

        if ($moneyRequest->recipient_user_id !== $userId) {
            abort(403);
        }

        $moneyRequest->update(['status' => MoneyRequest::STATUS_REJECTED]);

        $message = Message::whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) = ?",
            [self::MONEY_REQUEST_ID_JSON_PATH, $requestId],
        )->first();
        if ($message !== null && is_array($message->payload)) {
            $payload = $message->payload;
            $payload['status'] = 'declined';
            $message->update(['payload' => $payload]);
        }

        return response()->json(['status' => 'success']);
    }

    public function cancel(Request $request, string $requestId): JsonResponse
    {
        $moneyRequest = MoneyRequest::findOrFail($requestId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();

        if ($moneyRequest->requester_user_id !== $userId) {
            abort(403);
        }

        $moneyRequest->update(['status' => MoneyRequest::STATUS_REJECTED]);

        $message = Message::whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) = ?",
            [self::MONEY_REQUEST_ID_JSON_PATH, $requestId],
        )->first();
        if ($message !== null && is_array($message->payload)) {
            $payload = $message->payload;
            $payload['status'] = 'cancelled';
            $message->update(['payload' => $payload]);
        }

        return response()->json(['status' => 'success']);
    }

    public function amend(Request $request, string $requestId): JsonResponse
    {
        $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'note'   => 'sometimes|nullable|string|max:2000',
        ]);

        $moneyRequest = MoneyRequest::findOrFail($requestId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();

        if ($moneyRequest->requester_user_id !== $userId) {
            abort(403);
        }

        $updates = [];
        if ($request->has('amount')) {
            $updates['amount'] = $request->input('amount');
        }
        if ($request->has('note')) {
            $updates['note'] = $request->input('note');
        }
        $moneyRequest->update($updates);

        $message = Message::whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) = ?",
            [self::MONEY_REQUEST_ID_JSON_PATH, $requestId],
        )->first();
        if ($message !== null && is_array($message->payload)) {
            $payload = $message->payload;
            if (array_key_exists('amount', $updates)) {
                $payload['amount'] = (float) $updates['amount'];
            }
            if (array_key_exists('note', $updates)) {
                $payload['note'] = $updates['note'];
            }
            $message->update(['payload' => $payload]);
        }

        return response()->json(['status' => 'success']);
    }

    private function ensureActiveMember(Thread $thread, int $userId): void
    {
        $isMember = ThreadParticipant::where('thread_id', $thread->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            abort(403);
        }
    }
}
