<?php

declare(strict_types=1);

namespace App\Domain\Account\Actions;

use App\Domain\Account\Events\AccountDeleted;

class DeleteAccount extends AccountAction
{
    public function __invoke(AccountDeleted $event): ?bool
    {
        return $this->accountRepository->findByUuid(
            $event->aggregateRootUuid()
        )->delete();
    }
}
