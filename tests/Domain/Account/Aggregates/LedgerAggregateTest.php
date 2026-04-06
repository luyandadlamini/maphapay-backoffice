<?php

declare(strict_types=1);

namespace Tests\Domain\Account\Aggregates;

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\DataObjects\Account;
use App\Domain\Account\Events\AccountCreated;
use App\Domain\Account\Events\AccountDeleted;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class LedgerAggregateTest extends DomainTestCase
{
    private const string ACCOUNT_UUID = 'account-uuid';

    private const string ACCOUNT_NAME = 'fake-account';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDefaultAccounts();
    }

    #[Test]
    public function can_create(): void
    {
        LedgerAggregate::fake(self::ACCOUNT_UUID)
            ->given([])
            ->when(function (LedgerAggregate $ledger): void {
                $ledger->createAccount(
                    account: $this->fakeAccount()
                );
            })
            ->assertRecorded([
                new AccountCreated(
                    account: $this->fakeAccount()
                ),
            ]);
    }

    #[Test]
    public function can_delete_account(): void
    {
        LedgerAggregate::fake(self::ACCOUNT_UUID)
            ->given([
                new AccountCreated(
                    account: $this->fakeAccount()
                ),
            ])
            ->when(function (LedgerAggregate $ledger): void {
                $ledger->deleteAccount();
            })
            ->assertRecorded([
                new AccountDeleted(),
            ]);
    }

    protected function fakeAccount(): Account
    {
        return hydrate(
            Account::class,
            [
                'name'      => self::ACCOUNT_NAME,
                'user_uuid' => $this->business_user->uuid,
            ]
        );
    }
}
