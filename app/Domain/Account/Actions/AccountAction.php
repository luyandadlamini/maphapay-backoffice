<?php

declare(strict_types=1);

namespace App\Domain\Account\Actions;

use App\Domain\Account\Repositories\AccountRepository;

abstract class AccountAction
{
    public function __construct(
        protected AccountRepository $accountRepository
    ) {
    }
}
