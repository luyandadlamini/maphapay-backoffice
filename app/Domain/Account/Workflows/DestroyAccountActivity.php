<?php

declare(strict_types=1);

namespace App\Domain\Account\Workflows;

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use Workflow\Activity;

class DestroyAccountActivity extends Activity
{
    public function execute(AccountUuid $uuid, LedgerAggregate $ledger): bool
    {
        $ledger->retrieve($uuid->getUuid())
            ->deleteAccount()
            ->persist();

        return true;
    }
}
