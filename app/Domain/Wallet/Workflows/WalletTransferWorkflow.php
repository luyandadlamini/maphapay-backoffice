<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Workflows;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Wallet\Activities\TransferAssetActivity;
use Generator;
use Workflow\ActivityStub;
use Workflow\Workflow;

class WalletTransferWorkflow extends Workflow
{
    /**
     * Execute atomic wallet transfer between accounts.
     * Uses TransferAssetActivity to ensure consistent event emission
     * for transaction projections and balance updates.
     */
    public function execute(
        AccountUuid $fromAccountUuid,
        AccountUuid $toAccountUuid,
        string $assetCode,
        string $amount,
        ?string $reference = null
    ): Generator {
        // Use the activity reference for idempotency.
        // If not provided, we should ideally have one or generate one consistently.
        $reference = $reference ?? bin2hex(random_bytes(16));

        // Note: The TransferAssetActivity uses AssetTransferAggregate internally,
        // which handles both the initiation and completion of the transfer,
        // emitting the required events for TransactionProjector and AssetTransferProjector.
        yield ActivityStub::make(
            TransferAssetActivity::class,
            $fromAccountUuid,
            $toAccountUuid,
            $assetCode,
            $amount,
            $reference
        );

        return [
            'status'    => 'completed',
            'reference' => $reference,
        ];
    }
}
