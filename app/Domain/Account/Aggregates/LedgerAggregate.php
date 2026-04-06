<?php

declare(strict_types=1);

namespace App\Domain\Account\Aggregates;

use App\Domain\Account\DataObjects\Account;
use App\Domain\Account\Events\AccountCreated;
use App\Domain\Account\Events\AccountDeleted;
use App\Domain\Account\Events\AccountFrozen;
use App\Domain\Account\Events\AccountUnfrozen;
use App\Domain\Account\Repositories\LedgerRepository;
use App\Domain\Account\Repositories\LedgerSnapshotRepository;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class LedgerAggregate extends AggregateRoot
{
    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function getStoredEventRepository(): LedgerRepository
    {
        return app()->make(
            abstract: LedgerRepository::class
        );
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function getSnapshotRepository(): LedgerSnapshotRepository
    {
        return app()->make(
            abstract: LedgerSnapshotRepository::class
        );
    }

    /**
     * @return $this
     */
    public function createAccount(Account $account): static
    {
        $this->recordThat(
            domainEvent: new AccountCreated(
                account: $account
            )
        );

        return $this;
    }

    /**
     * @return $this
     */
    public function deleteAccount(): static
    {
        $this->recordThat(
            domainEvent: new AccountDeleted()
        );

        return $this;
    }

    /**
     * @return $this
     */
    public function freezeAccount(string $reason, ?string $authorizedBy = null): static
    {
        $this->recordThat(
            domainEvent: new AccountFrozen(
                reason: $reason,
                authorizedBy: $authorizedBy
            )
        );

        return $this;
    }

    /**
     * @return $this
     */
    public function unfreezeAccount(string $reason, ?string $authorizedBy = null): static
    {
        $this->recordThat(
            domainEvent: new AccountUnfrozen(
                reason: $reason,
                authorizedBy: $authorizedBy
            )
        );

        return $this;
    }
}
