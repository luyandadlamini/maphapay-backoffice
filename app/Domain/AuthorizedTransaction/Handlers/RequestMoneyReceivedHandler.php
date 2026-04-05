<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Handlers;

use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\InternalP2pTransferService;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Models\MoneyRequest;
use InvalidArgumentException;

/**
 * Finalizes acceptance of an incoming money request by executing a wallet transfer.
 *
 * Payload keys (set during initiation by RequestMoneyReceivedStoreController):
 *   - from_account_uuid      string  Payer's FinAegis account UUID
 *   - to_account_uuid        string  Requester's FinAegis account UUID
 *   - amount                 string  Major-unit SZL string e.g. "50.00"
 *   - asset_code             string  e.g. "SZL"
 *   - money_request_id       string  UUID of the MoneyRequest record to mark fulfilled
 *   - reference              string  Unique transfer reference
 */
class RequestMoneyReceivedHandler implements AuthorizedTransactionHandlerInterface
{
    public function __construct(
        private readonly InternalP2pTransferService $transferService,
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {
    }

    public function handle(AuthorizedTransaction $transaction): array
    {
        // Robust payload extraction to handle potential serialization drift.
        $payload = $transaction->payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }
        if (is_object($payload)) {
            $payload = (array) $payload;
        }

        $fromAccountUuid = $payload['from_account_uuid'] ?? null;
        $toAccountUuid = $payload['to_account_uuid'] ?? null;
        $amountStr = $payload['amount'] ?? null;
        $assetCode = $payload['asset_code'] ?? 'SZL';
        $moneyRequestId = $payload['money_request_id'] ?? null;
        $reference = $payload['reference'] ?? $transaction->trx;
        $note = $payload['note'] ?? null;

        if (! $fromAccountUuid || ! $toAccountUuid || ! $amountStr) {
            throw new InvalidArgumentException('RequestMoneyReceivedHandler: missing required payload keys.');
        }

        // Guard: Prevent zero or negative amount transactions from being finalized.
        if ((float) $amountStr <= 0) {
            throw new InvalidArgumentException("RequestMoneyReceivedHandler: Invalid transaction amount detected: {$amountStr}");
        }

        if (! is_string($moneyRequestId) || $moneyRequestId === '') {
            throw new InvalidArgumentException('RequestMoneyReceivedHandler: money_request_id must be a non-empty string UUID.');
        }

        $transfer = $this->transferService->execute(
            fromAccountUuid: (string) $fromAccountUuid,
            toAccountUuid: (string) $toAccountUuid,
            amount: (string) $amountStr,
            assetCode: (string) $assetCode,
            reference: (string) $reference,
            operationType: 'request_money_accept',
            note: is_string($note) ? $note : null,
        );

        $moneyRequest = MoneyRequest::query()->where('id', $moneyRequestId)->first();
        if ($moneyRequest !== null) {
            $fromStatus = $moneyRequest->status;
            $moneyRequest->update([
                'status'  => MoneyRequest::STATUS_FULFILLED,
                'paid_at' => now(),
            ]);
            $moneyRequest->refresh();

            $this->telemetry->logMoneyRequestTransition($moneyRequest, $fromStatus, MoneyRequest::STATUS_FULFILLED, [
                'remark'                     => $transaction->remark,
                'authorized_transaction_trx' => $transaction->trx,
                'reference'                  => $transfer['reference'],
            ]);
        }

        return [
            'trx'              => $transaction->trx,
            'amount'           => $transfer['amount'],
            'asset_code'       => $transfer['asset_code'],
            'money_request_id' => $moneyRequestId,
            'reference'        => $transfer['reference'],
        ];
    }
}
