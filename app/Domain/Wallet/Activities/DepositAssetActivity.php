<?php

namespace App\Domain\Wallet\Activities;

use App\Domain\Account\Aggregates\AssetTransactionAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use Workflow\Activity;

class DepositAssetActivity extends Activity
{
    public function execute(
        AccountUuid $accountUuid,
        string $assetCode,
        string $amount
    ): bool {
        $assetTransaction = AssetTransactionAggregate::retrieve((string) $accountUuid);
        $assetTransaction->credit($assetCode, (int) $amount)
            ->persist();

        return true;
    }
}
