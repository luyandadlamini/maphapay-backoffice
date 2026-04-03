<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Services;

use App\Models\MoneyRequest;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SocialRequestMessageService
{
    private const TTL_DAYS = 14;

    public function ensureForMoneyRequest(MoneyRequest $moneyRequest, int $senderUserId, int $friendId): int
    {
        if ((int) $moneyRequest->requester_user_id !== $senderUserId) {
            throw new RuntimeException('Money request requester does not match the authenticated user.');
        }

        if ((int) $moneyRequest->recipient_user_id !== $friendId) {
            throw new RuntimeException('Chat friend does not match the money request recipient.');
        }

        $mappingKey = $this->moneyRequestMappingKey((string) $moneyRequest->id);
        $existingMessageId = Cache::get($mappingKey);
        if (is_numeric($existingMessageId) && $this->messageExistsInChat((int) $existingMessageId, $senderUserId, $friendId)) {
            return (int) $existingMessageId;
        }

        $messageId = $this->appendRequestMessage(
            $senderUserId,
            $friendId,
            [
                'type' => 'request',
                'text' => '',
                'request' => [
                    'amount' => (float) $moneyRequest->amount,
                    'note' => (string) ($moneyRequest->note ?? ''),
                    'status' => 'pending',
                    'moneyRequestId' => (string) $moneyRequest->id,
                ],
            ],
        );

        Cache::put($mappingKey, $messageId, now()->addDays(self::TTL_DAYS));

        return $messageId;
    }

    public function linkExistingMoneyRequest(string $moneyRequestId, int $senderUserId, int $friendId): int
    {
        /** @var MoneyRequest $moneyRequest */
        $moneyRequest = MoneyRequest::query()->whereKey($moneyRequestId)->firstOrFail();

        return $this->ensureForMoneyRequest($moneyRequest, $senderUserId, $friendId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function appendRequestMessage(int $senderUserId, int $friendId, array $payload): int
    {
        $key = $this->chatKey($senderUserId, $friendId);
        $messageId = (int) Cache::increment('social_chat_msg_seq');
        $messages = Cache::get($key, []);
        if (! is_array($messages)) {
            $messages = [];
        }

        $messages[] = array_merge([
            'id' => (string) $messageId,
            'senderId' => (string) $senderUserId,
            'timestamp' => now()->toISOString(),
            'status' => 'sent',
        ], $payload);

        Cache::put($key, $messages, now()->addDays(self::TTL_DAYS));

        return $messageId;
    }

    private function messageExistsInChat(int $messageId, int $a, int $b): bool
    {
        $messages = Cache::get($this->chatKey($a, $b), []);
        if (! is_array($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            if (is_array($message) && (string) ($message['id'] ?? '') === (string) $messageId) {
                return true;
            }
        }

        return false;
    }

    private function moneyRequestMappingKey(string $moneyRequestId): string
    {
        return "social_request_message:{$moneyRequestId}";
    }

    private function chatKey(int $a, int $b): string
    {
        $x = min($a, $b);
        $y = max($a, $b);

        return "social_chat:{$x}:{$y}";
    }
}
