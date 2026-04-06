<?php

declare(strict_types=1);

namespace App\Domain\Account\Actions;

use App\Domain\Account\Events\AccountFrozen;
use App\Domain\Account\Models\Account;

class FreezeAccount
{
    public function __invoke(AccountFrozen $event): void
    {
        $account = Account::query()
            ->where('uuid', $event->aggregateRootUuid())
            ->firstOrFail();

        $account->update(
            [
                'frozen' => true,
            ]
        );
    }
}
