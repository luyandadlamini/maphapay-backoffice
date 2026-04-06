<?php

declare(strict_types=1);

namespace App\Domain\Account\Actions;

use App\Domain\Account\Events\AccountCreated;
use App\Domain\Account\Models\Account;

class CreateAccount extends AccountAction
{
    public function __invoke(AccountCreated $event): Account
    {
        return $this->accountRepository->create(
            $event->account->withUuid(
                $event->aggregateRootUuid()
            )
        );
    }
}
