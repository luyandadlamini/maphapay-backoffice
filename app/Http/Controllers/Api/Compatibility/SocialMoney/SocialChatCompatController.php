<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Domain\SocialMoney\Events\Broadcast\SocialTypingUpdated;
use App\Domain\SocialMoney\Services\SocialRequestMessageService;
use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Minimal chat/bill-split compat endpoints to prevent mobile social flow hard-stops.
 * Persists lightweight message rows in cache (no schema migration required).
 */
class SocialChatCompatController extends Controller
{
    public function __construct(
        private readonly SocialRequestMessageService $requestMessageService,
    ) {}

    public function messages(Request $request, int $friendId): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $key = $this->chatKey($userId, $friendId);
        $messages = Cache::get($key, []);

        return response()->json([
            'status' => 'success',
            'remark' => 'social_messages',
            'data' => [
                'messages' => is_array($messages) ? $messages : [],
                'next_cursor' => null,
                'has_more' => false,
            ],
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'friendId' => ['required', 'integer'],
            'text' => ['required', 'string', 'max:4000'],
        ]);

        $senderUserId = (int) $request->user()->getAuthIdentifier();
        $friendId = (int) $validated['friendId'];
        $messageId = $this->appendMessage(
            $senderUserId,
            $friendId,
            [
                'type' => 'text',
                'text' => (string) $validated['text'],
            ],
        );
        $this->broadcastChatMessageToPeer($senderUserId, $friendId, $messageId);
        $this->broadcastTypingStateToPeer($senderUserId, $friendId, false);

        return $this->ok(['messageId' => $messageId], 'social_send');
    }

    public function sendBillSplit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'friendId' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:1000'],
            'totalAmount' => ['required', 'numeric', 'min:0'],
            'splitMethod' => ['required', 'string'],
            'participants' => ['required', 'array', 'min:1'],
        ]);

        $senderUserId = (int) $request->user()->getAuthIdentifier();
        $friendId = (int) $validated['friendId'];
        $billSplitId = (int) Cache::increment('social_bill_split_seq');
        $messageId = $this->appendMessage(
            $senderUserId,
            $friendId,
            [
                'type' => 'bill_split',
                'text' => '',
                'billSplit' => [
                    'id' => (string) $billSplitId,
                    'description' => (string) $validated['description'],
                    'totalAmount' => (float) $validated['totalAmount'],
                    'splitMethod' => (string) $validated['splitMethod'],
                    'participants' => $validated['participants'],
                ],
            ],
        );
        $this->broadcastChatMessageToPeer($senderUserId, $friendId, $messageId);

        return $this->ok(['messageId' => $messageId, 'billSplitId' => $billSplitId], 'social_send_bill_split');
    }

    public function markPaid(Request $request): JsonResponse
    {
        $request->validate([
            'messageId' => ['required', 'integer'],
            'participantId' => ['required'],
        ]);

        return $this->ok([], 'social_mark_paid');
    }

    public function sendPaymentMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'friendId' => ['required', 'integer'],
            'trx' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'requestMessageId' => ['nullable', 'integer'],
        ]);

        $senderUserId = (int) $request->user()->getAuthIdentifier();
        $friendId = (int) $validated['friendId'];
        $messageId = $this->appendMessage(
            $senderUserId,
            $friendId,
            [
                'type' => 'payment',
                'text' => '',
                'payment' => [
                    'amount' => (float) $validated['amount'],
                    'note' => (string) ($validated['note'] ?? ''),
                ],
            ],
        );
        $this->broadcastChatMessageToPeer($senderUserId, $friendId, $messageId);

        return $this->ok(['messageId' => $messageId], 'social_send_payment_message');
    }

    public function sendRequestMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'friendId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'moneyRequestId' => ['sometimes', 'nullable', 'string'],
        ]);

        $senderUserId = (int) $request->user()->getAuthIdentifier();
        $friendId = (int) $validated['friendId'];
        $moneyRequestId = isset($validated['moneyRequestId']) ? (string) $validated['moneyRequestId'] : null;

        if ($moneyRequestId !== null && $moneyRequestId !== '') {
            $messageId = $this->requestMessageService->linkExistingMoneyRequest($moneyRequestId, $senderUserId, $friendId);
        } else {
            $messageId = $this->requestMessageService->appendRequestMessage(
                $senderUserId,
                $friendId,
                [
                    'type' => 'request',
                    'text' => '',
                    'request' => [
                        'amount' => (float) $validated['amount'],
                        'note' => (string) ($validated['note'] ?? ''),
                        'status' => 'pending',
                    ],
                ],
            );
        }
        $this->broadcastChatMessageToPeer($senderUserId, $friendId, $messageId);

        return $this->ok(['messageId' => $messageId], 'social_send_request_message');
    }

    public function typing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'friendId' => ['required', 'integer'],
            'isTyping' => ['required', 'boolean'],
        ]);

        $senderUserId = (int) $request->user()->getAuthIdentifier();
        $friendId = (int) $validated['friendId'];
        $this->assertAcceptedFriendship($senderUserId, $friendId);
        $this->broadcastTypingStateToPeer($senderUserId, $friendId, (bool) $validated['isTyping']);

        return $this->ok([], 'social_typing');
    }

    public function linkRequestMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'friendId' => ['required', 'integer'],
            'moneyRequestId' => ['required', 'string'],
        ]);

        /** @var MoneyRequest $moneyRequest */
        $moneyRequest = MoneyRequest::query()->whereKey((string) $validated['moneyRequestId'])->firstOrFail();

        $messageId = $this->requestMessageService->ensureForMoneyRequest(
            $moneyRequest,
            (int) $request->user()->getAuthIdentifier(),
            (int) $validated['friendId'],
        );

        return $this->ok([
            'messageId' => $messageId,
            'moneyRequestId' => (string) $moneyRequest->id,
            'chatLinked' => true,
        ], 'social_link_request_message');
    }

    public function amendRequestMessage(Request $request): JsonResponse
    {
        $request->validate([
            'messageId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        return $this->ok([], 'social_amend_request_message');
    }

    public function declineRequestMessage(Request $request): JsonResponse
    {
        $request->validate(['messageId' => ['required', 'integer']]);
        return $this->ok([], 'social_decline_request_message');
    }

    public function cancelRequestMessage(Request $request): JsonResponse
    {
        $request->validate(['messageId' => ['required', 'integer']]);
        return $this->ok([], 'social_cancel_request_message');
    }

    private function appendMessage(int $senderId, int $friendId, array $payload): int
    {
        $key = $this->chatKey($senderId, $friendId);
        $messageId = (int) Cache::increment('social_chat_msg_seq');
        $messages = Cache::get($key, []);
        if (! is_array($messages)) {
            $messages = [];
        }

        $messages[] = array_merge([
            'id' => (string) $messageId,
            'senderId' => (string) $senderId,
            'timestamp' => now()->toISOString(),
            'status' => 'sent',
        ], $payload);

        Cache::put($key, $messages, now()->addDays(14));
        return $messageId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getConversationMessageById(int $userId, int $friendId, int $messageId): ?array
    {
        $messages = Cache::get($this->chatKey($userId, $friendId), []);
        if (! is_array($messages)) {
            return null;
        }

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            if ((string) ($message['id'] ?? '') === (string) $messageId) {
                return $message;
            }
        }

        return null;
    }

    private function broadcastChatMessageToPeer(int $senderUserId, int $friendId, int $messageId): void
    {
        $message = $this->getConversationMessageById($senderUserId, $friendId, $messageId);
        if ($message === null) {
            return;
        }

        broadcast(new ChatMessageSent(
            recipientId: $friendId,
            senderId: $senderUserId,
            message: $message,
        ));
    }

    private function broadcastTypingStateToPeer(int $senderUserId, int $friendId, bool $isTyping): void
    {
        /** @var User|null $sender */
        $sender = User::query()->find($senderUserId);
        $displayName = trim((string) ($sender?->name ?? 'User'));
        $expiresAt = now()->addSeconds($isTyping ? 5 : 0)->toISOString();

        broadcast(new SocialTypingUpdated(
            recipientId: $friendId,
            conversationKey: (string) $senderUserId,
            actorUserId: $senderUserId,
            actorDisplayName: $displayName !== '' ? $displayName : 'User',
            isTyping: $isTyping,
            expiresAt: $expiresAt,
        ));
    }

    private function assertAcceptedFriendship(int $userId, int $friendId): void
    {
        $isAcceptedFriend = DB::table('friendships')
            ->where('user_id', $userId)
            ->where('friend_id', $friendId)
            ->where('status', 'accepted')
            ->exists();

        if ($isAcceptedFriend) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'remark' => 'social_conversation_forbidden',
            'message' => ['You do not have access to this conversation.'],
        ], 403));
    }

    private function chatKey(int $a, int $b): string
    {
        $x = min($a, $b);
        $y = max($a, $b);
        return "social_chat:{$x}:{$y}";
    }

    private function ok(array $data, string $remark): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'remark' => $remark,
            'data' => $data,
        ]);
    }
}
