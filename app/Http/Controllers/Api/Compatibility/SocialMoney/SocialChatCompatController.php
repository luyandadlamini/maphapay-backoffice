<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SocialMoney;

use App\Domain\SocialMoney\Services\SocialRequestMessageService;
use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        $messageId = $this->appendMessage(
            (int) $request->user()->getAuthIdentifier(),
            (int) $validated['friendId'],
            [
                'type' => 'text',
                'text' => (string) $validated['text'],
            ],
        );

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

        $billSplitId = (int) Cache::increment('social_bill_split_seq');
        $messageId = $this->appendMessage(
            (int) $request->user()->getAuthIdentifier(),
            (int) $validated['friendId'],
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

        $messageId = $this->appendMessage(
            (int) $request->user()->getAuthIdentifier(),
            (int) $validated['friendId'],
            [
                'type' => 'payment',
                'text' => '',
                'payment' => [
                    'amount' => (float) $validated['amount'],
                    'note' => (string) ($validated['note'] ?? ''),
                ],
            ],
        );

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

        return $this->ok(['messageId' => $messageId], 'social_send_request_message');
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
