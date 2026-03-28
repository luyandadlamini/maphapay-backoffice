<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Handlers;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Services\WalletOperationsService;
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
        $moneyRequestId = $payload['money_request_id'] ?? null;
        $reference = $payload['reference'] ?? $transaction->trx;

        if (! $fromAccountUuid || ! $toAccountUuid || ! $amountStr) {
            throw new InvalidArgumentException('RequestMoneyReceivedHandler: missing required payload keys.');
        }

        if (! is_string($moneyRequestId) || $moneyRequestId === '') {
            throw new InvalidArgumentException('RequestMoneyReceivedHandler: money_request_id must be a non-empty string UUID.');
        }

        $asset = Asset::query()->where('code', $assetCode)->firstOrFail();
        $amountMinor = MoneyConverter::forAsset((string) $amountStr, $asset);

        $this->walletOps->transfer(
            fromWalletId: $fromAccountUuid,
            toWalletId:   $toAccountUuid,
            assetCode:    $assetCode,
            amount:       (string) $amountMinor,
            reference:    $reference,
            metadata:     [
                'trx'              => $transaction->trx,
                'remark'           => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
                'money_request_id' => $moneyRequestId,
                'authorized_txn'   => $transaction->id,
            ],
        );

        MoneyRequest::query()
            ->where('id', $moneyRequestId)
            ->update(['status' => MoneyRequest::STATUS_FULFILLED]);

        return [
            'trx'              => $transaction->trx,
            'amount'           => MoneyConverter::toMajorUnitString($amountMinor, $asset->precision),
            'asset_code'       => $assetCode,
            'money_request_id' => $moneyRequestId,
        ];
    }
}
