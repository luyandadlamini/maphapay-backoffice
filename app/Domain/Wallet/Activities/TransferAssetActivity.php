<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Activities;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Asset\Aggregates\AssetTransferAggregate;
use Workflow\Activity;

class TransferAssetActivity extends Activity
{
    /**
     * Executes an atomic transfer using the AssetTransferAggregate.
     * This ensures that AssetTransferInitiated and AssetTransferCompleted
     * events are fired, which are required for transaction projections.
     */
    public function execute(
        AccountUuid $fromAccountUuid,
        AccountUuid $toAccountUuid,
        string $assetCode,
        string $amount,
        string $reference = ''
    ): array {
        // Map the string amount (minor units) to the Money data object.
        $fromMoney = new Money((int) $amount);
        $toMoney = new Money((int) $amount);

        // Retrieve the aggregate using the reference as the unique identifier.
        // This provides natural idempotency if the same reference is used.
        $assetTransfer = AssetTransferAggregate::retrieve($reference);
        
        $assetTransfer->initiate(
            fromAccountUuid: $fromAccountUuid,
            toAccountUuid:   $toAccountUuid,
            fromAssetCode:   $assetCode,
            toAssetCode:     $assetCode,
            fromAmount:      $fromMoney,
            toAmount:        $toMoney,
            description:     "Transfer: {$reference}"
        )
        ->complete($reference)
        ->persist();

        return [
            'success'      => true,
            'transfer_id'  => $reference,
            'from_account' => $fromAccountUuid->toString(),
            'to_account'   => $toAccountUuid->toString(),
            'amount'       => $amount,
            'asset_code'   => $assetCode,
        ];
    }
}
