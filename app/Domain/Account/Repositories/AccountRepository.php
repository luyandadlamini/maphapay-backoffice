<?php

declare(strict_types=1);

namespace App\Domain\Account\Repositories;

use App\Domain\Account\DataObjects\Account as AccountDTO;
use App\Domain\Account\Models\Account;
use Illuminate\Support\LazyCollection;

final class AccountRepository
{
    public function __construct(
        protected Account $account
    ) {
    }

    public function create(AccountDTO $account): Account
    {
        return $this->account->create($account->toArray());
    }

    public function findByUuid(string $uuid): Account
    {
        return $this->account->where('uuid', $uuid)->firstOrFail();
    }

    public function getAllByCursor(): LazyCollection
    {
        return $this->account->cursor();
    }
}
