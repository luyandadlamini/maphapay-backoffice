<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Handlers;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Activities\TransferAssetActivity;
use InvalidArgumentException;

/**
 * Finalizes a send_money operation by executing a wallet transfer.
 *
 * Payload keys (set during initiation):
 *   - from_account_uuid  string  Source FinAegis account UUID
 *   - to_account_uuid    string  Destination FinAegis account UUID
 *   - amount             string  Major-unit SZL string e.g. "25.10"
 *   - asset_code         string  e.g. "SZL"
 *   - reference          string  Unique transfer reference
 *   - note               string  Optional user note
 */
class SendMoneyHandler implements AuthorizedTransactionHandlerInterface
{
    public function __construct(
        private readonly TransferAssetActivity $transferAssetActivity,
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
        $reference = $payload['reference'] ?? $transaction->trx;
        $note = $payload['note'] ?? '';

        if (! $fromAccountUuid || ! $toAccountUuid || ! $amountStr) {
            throw new InvalidArgumentException('SendMoneyHandler: missing required payload keys.');
        }

        // Guard: Prevent zero or negative amount transactions from being finalized.
        if ((float) $amountStr <= 0) {
            throw new InvalidArgumentException("SendMoneyHandler: Invalid transaction amount detected: {$amountStr}");
        }

        $asset = Asset::query()->where('code', $assetCode)->firstOrFail();

        // Convert to minor units using precision-safe bcmath converter.
        $amountMinor = MoneyConverter::forAsset((string) $amountStr, $asset);

        $this->transferAssetActivity->execute(
            fromAccountUuid: __account_uuid($fromAccountUuid),
            toAccountUuid: __account_uuid($toAccountUuid),
            assetCode: $assetCode,
            amount: (string) $amountMinor,
            reference: $reference,
        );

        return [
            'trx'        => $transaction->trx,
            'amount'     => MoneyConverter::toMajorUnitString($amountMinor, $asset->precision),
            'asset_code' => $assetCode,
            'reference'  => $reference,
        ];
    }
}
