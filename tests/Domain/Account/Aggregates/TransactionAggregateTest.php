<?php

declare(strict_types=1);

namespace Tests\Domain\Account\Aggregates;

use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Events\AccountLimitHit;
use App\Domain\Account\Events\MoneyAdded;
use App\Domain\Account\Events\MoneySubtracted;
use App\Domain\Account\Exceptions\InvalidHashException;
use App\Domain\Account\Exceptions\NotEnoughFunds;
use App\Domain\Account\Utils\ValidatesHash;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class TransactionAggregateTest extends DomainTestCase
{
    use ValidatesHash;

    private const string ACCOUNT_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const string ACCOUNT_NAME = 'fake-account';

    #[Test]
    public function can_add_money(): void
    {
        TransactionAggregate::fake(self::ACCOUNT_UUID)
            ->when(
                function (
                    TransactionAggregate $transactions
                ): void {
                    $transactions->credit(
                        money: $this->money(10)
                    );
                }
            )
            ->assertRecorded([
                new MoneyAdded(
                    money: $money = $this->money(10),
                    hash: $this->generateHash($money)
                ),
            ]);
    }

    #[Test]
    public function can_subtract_money(): void
    {
        $added_money = $this->money(10);
        $added_hash = $this->generateHash($added_money);
        $this->resetHash($added_hash->getHash());

        TransactionAggregate::fake(self::ACCOUNT_UUID)
            ->given([
                new MoneyAdded(
                    $added_money,
                    $added_hash
                ),
            ])
            ->when(
                function (
                    TransactionAggregate $transactions
                ): void {
                    $transactions->debit(
                        $this->money(10)
                    );
                }
            )
            ->assertRecorded([
                new MoneySubtracted(
                    $subtracted_money = $this->money(10),
                    $this->generateHash($subtracted_money)
                ),
            ])
            ->assertNotRecorded(AccountLimitHit::class);
    }

    #[Test]
    public function cannot_subtract_money_when_money_below_account_limit(): void
    {
        TransactionAggregate::fake(self::ACCOUNT_UUID)
            ->when(
                function (
                    TransactionAggregate $transactions
                ): void {
                    $this->assertExceptionThrown(
                        function () use ($transactions) {
                            $transactions->debit(
                                $this->money(1)
                            );
                        },
                        NotEnoughFunds::class
                    );
                }
            )
            ->assertApplied([
                new AccountLimitHit(),
            ])
            ->assertNotRecorded(MoneySubtracted::class);
    }

    #[Test]
    public function throws_exception_on_invalid_transaction_hash(): void
    {
        $initialMoney = $this->money(10);
        $validHash = $this->generateHash($initialMoney);
        $this->resetHash($validHash->getHash());

        TransactionAggregate::fake(self::ACCOUNT_UUID)
            ->given([
                new MoneyAdded(
                    money: $initialMoney,
                    hash: $validHash
                ),
            ])
            ->when(
                function (
                    TransactionAggregate $transactions
                ): void {
                    $this->assertExceptionThrown(
                        function () use ($transactions) {
                            $transactions->applyMoneyAdded(
                                new MoneyAdded(
                                    money: $this->money(10),
                                    hash: $this->hash(
                                        'invalid-hash'
                                    ),
                                )
                            );
                        },
                        InvalidHashException::class
                    );
                }
            );
    }

    #[Test]
    public function cannot_record_event_with_invalid_hash(): void
    {
        $initialMoney = $this->money(10);
        $validHash = $this->generateHash($initialMoney);
        $this->resetHash($validHash->getHash());

        TransactionAggregate::fake(self::ACCOUNT_UUID)
            ->given([
                new MoneyAdded(
                    money: $initialMoney,
                    hash: $validHash
                ),
            ])
            ->when(
                function (
                    TransactionAggregate $transactions
                ): void {
                    $this->assertExceptionThrown(
                        function () use ($transactions) {
                            $transactions->recordThat(
                                new MoneyAdded(
                                    money: $this->money(10),
                                    hash: $this->hash(
                                        'invalid-hash'
                                    ),
                                )
                            );
                        },
                        InvalidHashException::class
                    );
                }
            );
    }

    private function money(int $amount): Money
    {
        return hydrate(Money::class, ['amount' => $amount]);
    }

    private function hash(?string $hash = ''): Hash
    {
        return hydrate(
            Hash::class,
            ['hash' => hash(self::HASH_ALGORITHM, $hash)]
        );
    }
}
