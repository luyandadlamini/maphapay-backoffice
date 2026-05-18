<?php

declare(strict_types=1);

namespace App\Domain\SocialMoney\Observers;

use App\Domain\AuthorizedTransaction\Events\AuthorizedTransactionFinalized;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\SocialMoney\Services\SyncTransactionToChatService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Listens to AuthorizedTransactionFinalized (dispatched from
 * AuthorizedTransactionManager::finalizeAtomically) and posts a chat
 * bubble between the sender and recipient if they are friends.
 *
 * Despite the "Observer" name, this is wired as an event listener — the
 * manager flips status via a raw query-builder UPDATE so Eloquent model
 * observers do not fire on the transition.
 */
class AuthorizedTransactionChatSyncObserver
{
    public function __construct(
        private readonly SyncTransactionToChatService $sync,
    ) {
    }

    public function handle(AuthorizedTransactionFinalized $event): void
    {
        $txn = $event->transaction;

        if ($txn->status !== AuthorizedTransaction::STATUS_COMPLETED) {
            return;
        }
        if (! in_array($txn->remark, [
            AuthorizedTransaction::REMARK_SEND_MONEY,
            AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
        ], true)) {
            return;
        }

        $payload = is_array($txn->payload) ? $txn->payload : [];
        $senderUserId = (int) $txn->user_id;
        $recipientUserId = (int) ($payload['recipient_user_id'] ?? 0);

        if ($recipientUserId === 0 || $recipientUserId === $senderUserId) {
            return;
        }

        $amount = (float) ($payload['amount'] ?? 0);
        $assetCode = isset($payload['asset_code']) ? (string) $payload['asset_code'] : null;
        $note = isset($payload['note']) ? (string) $payload['note'] : null;
        $authorizedTransactionId = (string) $txn->id;

        DB::afterCommit(function () use ($senderUserId, $recipientUserId, $amount, $assetCode, $note, $authorizedTransactionId): void {
            try {
                $this->sync->postPaymentMessage(
                    senderUserId: $senderUserId,
                    recipientUserId: $recipientUserId,
                    amount: $amount,
                    assetCode: $assetCode,
                    note: $note,
                    authorizedTransactionId: $authorizedTransactionId,
                );
            } catch (Throwable $e) {
                // Chat-sync failures must NOT roll back wallet transactions.
                report($e);
            }
        });
    }
}
