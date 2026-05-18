<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Services;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Models\Message;
use App\Models\MoneyRequest;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncTransactionToChatService
{
    public function __construct(
        private readonly ThreadResolver $threadResolver,
    ) {
    }

    /**
     * Post a `payment` chat message after a send-money transaction completes
     * outside of the chat screen. No-ops if the two users are not friends.
     */
    public function postPaymentMessage(
        int $senderUserId,
        int $recipientUserId,
        float $amount,
        ?string $assetCode,
        ?string $note,
        string $authorizedTransactionId,
    ): void {
        if (! $this->areFriends($senderUserId, $recipientUserId)) {
            return;
        }

        $thread = $this->threadResolver->findOrCreateDirect($senderUserId, $recipientUserId);
        if ($thread === null) {
            return;
        }

        $idempotencyKey = "tx:{$authorizedTransactionId}";
        if ($this->messageExists($idempotencyKey)) {
            return;
        }

        $message = Message::create([
            'thread_id' => $thread->id,
            'sender_id' => $senderUserId,
            'type'      => 'payment',
            'text'      => 'Payment sent',
            'payload'   => [
                'amount'                  => $amount,
                'assetCode'               => $assetCode,
                'note'                    => $note,
                'recipientUserId'         => (string) $recipientUserId,
                'linkedRequestId'         => null,
                'authorizedTransactionId' => $authorizedTransactionId,
            ],
            'idempotency_key' => $idempotencyKey,
            'created_at'      => now(),
        ]);

        $this->broadcast($thread, $senderUserId, $message, 'payment', 'Payment sent');
    }

    /**
     * Post a `request` chat message after a money request is created
     * outside of the chat screen.
     */
    public function postRequestMessage(MoneyRequest $request): void
    {
        $requesterId = (int) $request->requester_user_id;
        $recipientId = (int) $request->recipient_user_id;

        if (! $this->areFriends($requesterId, $recipientId)) {
            return;
        }

        $thread = $this->threadResolver->findOrCreateDirect($requesterId, $recipientId);
        if ($thread === null) {
            return;
        }

        $idempotencyKey = "mr:{$request->id}:created";
        if ($this->messageExists($idempotencyKey)) {
            return;
        }

        $message = Message::create([
            'thread_id' => $thread->id,
            'sender_id' => $requesterId,
            'type'      => 'request',
            'text'      => 'Money requested',
            'payload'   => [
                'moneyRequestId' => (string) $request->id,
                'amount'         => (float) $request->amount,
                'assetCode'      => $request->asset_code,
                'note'           => $request->note,
                'status'         => 'pending',
                'targetUserId'   => (string) $recipientId,
            ],
            'idempotency_key' => $idempotencyKey,
            'created_at'      => now(),
        ]);

        $this->broadcast($thread, $requesterId, $message, 'request', 'Money requested');
    }

    /**
     * Mutate the existing request bubble's payload to mark it as paid.
     * Matches the in-place convention used by ThreadPaymentController.
     */
    public function markRequestPaid(MoneyRequest $request): void
    {
        $idempotencyKey = "mr:{$request->id}:created";
        $message = Message::where('idempotency_key', $idempotencyKey)->first();
        if ($message === null || ! is_array($message->payload)) {
            return;
        }

        $payload = $message->payload;
        if (($payload['status'] ?? null) === 'paid') {
            return;
        }
        $payload['status'] = 'paid';
        $message->update(['payload' => $payload]);

        $thread = $message->thread;
        if ($thread === null) {
            return;
        }

        // Re-broadcast so listeners refresh the bubble.
        $this->broadcast($thread, (int) $message->sender_id, $message, 'request', 'Request paid');
    }

    /**
     * Post a small system message noting a declined money request, and flip
     * the original request bubble's payload status to `declined`.
     */
    public function postRequestDeclined(MoneyRequest $request): void
    {
        $requesterId = (int) $request->requester_user_id;
        $recipientId = (int) $request->recipient_user_id;

        if (! $this->areFriends($requesterId, $recipientId)) {
            return;
        }

        $thread = $this->threadResolver->findOrCreateDirect($requesterId, $recipientId);
        if ($thread === null) {
            return;
        }

        // Flip the linked request bubble's status only if still pending — the
        // in-chat decline/cancel controllers already mutate to 'declined' or
        // 'cancelled' themselves and we must not clobber their distinction.
        $requestMessage = Message::where('idempotency_key', "mr:{$request->id}:created")->first();
        if ($requestMessage !== null && is_array($requestMessage->payload)) {
            $payload = $requestMessage->payload;
            if (($payload['status'] ?? null) === 'pending') {
                $payload['status'] = 'declined';
                $requestMessage->update(['payload' => $payload]);
            }
        }

        $idempotencyKey = "mr:{$request->id}:declined";
        if ($this->messageExists($idempotencyKey)) {
            return;
        }

        // The recipient (who is declining) is the sender of this system message.
        $message = Message::create([
            'thread_id' => $thread->id,
            'sender_id' => $recipientId,
            'type'      => 'system',
            'text'      => 'Request declined',
            'payload'   => [
                'kind'           => 'money_request_declined',
                'moneyRequestId' => (string) $request->id,
                'amount'         => (float) $request->amount,
            ],
            'idempotency_key' => $idempotencyKey,
            'created_at'      => now(),
        ]);

        $this->broadcast($thread, $recipientId, $message, 'system', 'Request declined');
    }

    private function areFriends(int $userA, int $userB): bool
    {
        return DB::table('friendships')
            ->where('user_id', $userA)
            ->where('friend_id', $userB)
            ->where('status', 'accepted')
            ->exists();
    }

    private function messageExists(string $idempotencyKey): bool
    {
        return Message::where('idempotency_key', $idempotencyKey)->exists();
    }

    private function broadcast(Thread $thread, int $senderId, Message $message, string $messageType, string $preview): void
    {
        $senderName = (string) (User::where('id', $senderId)->value('name') ?? '');

        $recipientIds = ThreadParticipant::where('thread_id', $thread->id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $senderId)
            ->pluck('user_id');

        foreach ($recipientIds as $recipientId) {
            event(new ChatMessageSent(
                recipientId: (int) $recipientId,
                threadId: $thread->id,
                threadType: $thread->type,
                senderId: $senderId,
                senderName: $senderName,
                messageId: $message->id,
                messageType: $messageType,
                preview: $preview,
            ));
        }
    }
}
