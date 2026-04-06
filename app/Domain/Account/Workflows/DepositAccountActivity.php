<?php

declare(strict_types=1);

namespace App\Domain\Account\Workflows;

use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use Workflow\Activity;

class DepositAccountActivity extends Activity
{
    public function execute(AccountUuid $uuid, Money $money, TransactionAggregate $transaction): bool
    {
        $transaction->retrieve($uuid->getUuid())
            ->credit($money)
            ->persist();

        return true;
    }
}
