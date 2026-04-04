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

class ThreadPaymentController extends Controller
{
    private const MONEY_REQUEST_ID_JSON_PATH = '$.moneyRequestId';

    public function store(Request $request, int $threadId): JsonResponse
    {
        $request->validate([
            'amount'          => 'required|numeric|min:0.01',
            'note'            => 'nullable|string|max:2000',
            'recipientUserId' => 'required|integer',
            'linkedRequestId' => 'nullable|string',
        ]);

        $thread = Thread::findOrFail($threadId);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $userId = (int) $user->getAuthIdentifier();
        $this->ensureActiveMember($thread, $userId);

        $message = Message::create([
            'thread_id' => $thread->id,
            'sender_id' => $userId,
            'type'      => 'payment',
            'text'      => 'Payment sent',
            'payload'   => [
                'amount'          => (float) $request->input('amount'),
                'note'            => $request->input('note'),
                'recipientUserId' => (string) $request->input('recipientUserId'),
                'linkedRequestId' => $request->input('linkedRequestId'),
            ],
            'created_at' => now(),
        ]);

        $linkedRequestId = $request->input('linkedRequestId');
        if (is_string($linkedRequestId) && $linkedRequestId !== '') {
            $moneyRequest = MoneyRequest::find($linkedRequestId);
            if ($moneyRequest !== null) {
                $moneyRequest->update(['status' => MoneyRequest::STATUS_FULFILLED]);

                $requestMessage = Message::whereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT(payload, ?)) = ?",
                    [self::MONEY_REQUEST_ID_JSON_PATH, $moneyRequest->id],
                )->first();
                if ($requestMessage !== null && is_array($requestMessage->payload)) {
                    $payload = $requestMessage->payload;
                    $payload['status'] = 'paid';
                    $requestMessage->update(['payload' => $payload]);
                }
            }
        }

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
                messageId: $message->id,
                messageType: 'payment',
                preview: 'Payment sent',
            ));
        }

        return response()->json([
            'status' => 'success',
            'data'   => ['messageId' => (string) $message->id],
        ]);
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
