<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Activities;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Asset\Aggregates\AssetTransferAggregate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
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
        $reference = $reference !== '' ? $reference : (string) Str::uuid();

        if ($fromAccountUuid->toString() === $toAccountUuid->toString()) {
            throw new InvalidArgumentException('Cannot transfer to the same account');
        }

        if ((int) $amount <= 0) {
            throw new InvalidArgumentException('Transfer amount must be greater than zero');
        }

        // Map the string amount (minor units) to the Money data object.
        $fromMoney = new Money((int) $amount);
        $toMoney = new Money((int) $amount);

        // Retrieve the aggregate using the reference as the unique identifier.
        // This provides natural idempotency if the same reference is used.
        $assetTransfer = AssetTransferAggregate::retrieve($reference);

        if ($assetTransfer->getStatus() !== 'completed') {
            try {
                if ($assetTransfer->getStatus() === null) {
                    $assetTransfer->initiate(
                        fromAccountUuid: $fromAccountUuid,
                        toAccountUuid:   $toAccountUuid,
                        fromAssetCode:   $assetCode,
                        toAssetCode:     $assetCode,
                        fromAmount:      $fromMoney,
                        toAmount:        $toMoney,
                        description:     "Transfer: {$reference}"
                    );
                }

                $assetTransfer
                    ->complete($reference)
                    ->persist();
            } catch (Throwable $e) {
                Log::error('TransferAssetActivity failed to persist asset transfer', [
                    'reference'       => $reference,
                    'from_account'    => $fromAccountUuid->toString(),
                    'to_account'      => $toAccountUuid->toString(),
                    'asset_code'      => $assetCode,
                    'amount'          => $amount,
                    'aggregateStatus' => $assetTransfer->getStatus(),
                    'error'           => $e->getMessage(),
                ]);

                throw new RuntimeException(
                    sprintf('Failed to persist asset transfer [%s]: %s', $reference, $e->getMessage()),
                    previous: $e,
                );
            }
        }

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
