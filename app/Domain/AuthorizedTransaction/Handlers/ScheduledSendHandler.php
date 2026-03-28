<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Handlers;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\ScheduledSend;
use InvalidArgumentException;
use Throwable;

/**
 * Executes a scheduled send by triggering the wallet transfer at the scheduled time.
 *
 * Called by ExecuteScheduledSendsCommand (not by the verification flow directly)
 * but shares the same handler interface so it runs through the same
 * atomic check-and-set in AuthorizedTransactionManager.
 *
 * Payload keys match SendMoneyHandler with an additional:
 *   - scheduled_at  string  ISO 8601 timestamp (informational only at execution time)
 */
class ScheduledSendHandler implements AuthorizedTransactionHandlerInterface
{
    public function __construct(
        private readonly WalletOperationsService $walletOps,
    ) {
    }

    public function handle(AuthorizedTransaction $transaction): array
    {
        $payload = $transaction->payload;

        $fromAccountUuid = $payload['from_account_uuid'] ?? null;
        $toAccountUuid = $payload['to_account_uuid'] ?? null;
        $amountStr = $payload['amount'] ?? null;
        $assetCode = $payload['asset_code'] ?? 'SZL';
        $reference = $payload['reference'] ?? $transaction->trx;
        $note = $payload['note'] ?? '';
        $scheduledSendId = $payload['scheduled_send_id'] ?? null;

        if (! $fromAccountUuid || ! $toAccountUuid || ! $amountStr) {
            throw new InvalidArgumentException('ScheduledSendHandler: missing required payload keys.');
        }

        // Pre-transfer guard: abort if the row was cancelled (or executed by a concurrent call)
        // before the wallet operation runs. lockForUpdate() prevents a cancel sneaking in between
        // this read and the wallet write, since finalizeAtomically() wraps us in DB::transaction.
        if (is_string($scheduledSendId) && $scheduledSendId !== '') {
            $row = ScheduledSend::query()
                ->whereKey($scheduledSendId)
                ->where('sender_user_id', $transaction->user_id)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->status !== ScheduledSend::STATUS_PENDING) {
                throw new InvalidArgumentException(
                    'Scheduled send is no longer pending — transfer aborted.'
                );
            }
        }

        $asset = Asset::query()->where('code', $assetCode)->firstOrFail();
        $amountMinor = MoneyConverter::forAsset((string) $amountStr, $asset);

        try {
            $this->walletOps->transfer(
                fromWalletId: $fromAccountUuid,
                toWalletId:   $toAccountUuid,
                assetCode:    $assetCode,
                amount:       (string) $amountMinor,
                reference:    $reference,
                metadata:     [
                    'trx'            => $transaction->trx,
                    'remark'         => AuthorizedTransaction::REMARK_SCHEDULED_SEND,
                    'note'           => $note,
                    'scheduled_at'   => $payload['scheduled_at'] ?? null,
                    'authorized_txn' => $transaction->id,
                ],
            );
        } catch (Throwable $e) {
            // Mark the scheduled_send row failed so the user is not left with a phantom
            // pending entry they cannot retry (the authorized_transaction is now failed too).
            if (is_string($scheduledSendId) && $scheduledSendId !== '') {
                ScheduledSend::query()
                    ->whereKey($scheduledSendId)
                    ->where('sender_user_id', $transaction->user_id)
                    ->where('status', ScheduledSend::STATUS_PENDING)
                    ->update(['status' => ScheduledSend::STATUS_FAILED]);
            }

            throw $e;
        }

        if (is_string($scheduledSendId) && $scheduledSendId !== '') {
            ScheduledSend::query()
                ->whereKey($scheduledSendId)
                ->where('sender_user_id', $transaction->user_id)
                ->where('status', ScheduledSend::STATUS_PENDING)
                ->update(['status' => ScheduledSend::STATUS_EXECUTED]);
        }

        return [
            'trx'          => $transaction->trx,
            'amount'       => MoneyConverter::toMajorUnitString($amountMinor, $asset->precision),
            'asset_code'   => $assetCode,
            'reference'    => $reference,
            'scheduled_at' => $payload['scheduled_at'] ?? null,
        ];
    }
}
