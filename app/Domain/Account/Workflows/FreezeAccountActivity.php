<?php

declare(strict_types=1);

namespace App\Domain\Account\Workflows;

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use Workflow\Activity;

class FreezeAccountActivity extends Activity
{
    public function execute(
        AccountUuid $uuid,
        string $reason,
        ?string $authorizedBy,
        LedgerAggregate $ledger
    ): bool {
        $ledger->retrieve($uuid->getUuid())
            ->freezeAccount($reason, $authorizedBy)
            ->persist();

        return true;
    }
}
