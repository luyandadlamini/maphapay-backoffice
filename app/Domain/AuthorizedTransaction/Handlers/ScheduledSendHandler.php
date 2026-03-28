<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Handlers;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Services\WalletOperationsService;
use InvalidArgumentException;

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

        if (! $fromAccountUuid || ! $toAccountUuid || ! $amountStr) {
            throw new InvalidArgumentException('ScheduledSendHandler: missing required payload keys.');
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
                'trx'            => $transaction->trx,
                'remark'         => AuthorizedTransaction::REMARK_SCHEDULED_SEND,
                'note'           => $note,
                'scheduled_at'   => $payload['scheduled_at'] ?? null,
                'authorized_txn' => $transaction->id,
            ],
        );

        return [
            'trx'          => $transaction->trx,
            'amount'       => MoneyConverter::toMajorUnitString($amountMinor, $asset->precision),
            'asset_code'   => $assetCode,
            'reference'    => $reference,
            'scheduled_at' => $payload['scheduled_at'] ?? null,
        ];
    }
}
