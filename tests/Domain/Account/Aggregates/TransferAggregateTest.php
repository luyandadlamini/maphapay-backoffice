<?php

declare(strict_types=1);

namespace Tests\Domain\Account\Aggregates;

use App\Domain\Account\Aggregates\TransferAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Events\MoneyTransferred;
use App\Domain\Account\Exceptions\InvalidHashException;
use App\Domain\Account\Utils\ValidatesHash;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class TransferAggregateTest extends DomainTestCase
{
    use ValidatesHash;

    private const string TRANSFER_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeea';

    private const string ACCOUNT_FROM_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeeb';

    private const string ACCOUNT_TO_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeec';

    private const string ACCOUNT_NAME = 'fake-account';

    #[Test]
    public function can_transfer_money(): void
    {
        TransferAggregate::fake(self::TRANSFER_UUID)
            ->when(
                function (
                    TransferAggregate $transfers
                ): void {
                    $transfers->transfer(
                        from: $this->account_uuid(self::ACCOUNT_FROM_UUID),
                        to: $this->account_uuid(self::ACCOUNT_TO_UUID),
                        money: $this->money(10)
                    );
                }
            )
            ->assertRecorded([
                new MoneyTransferred(
                    from: $this->account_uuid(self::ACCOUNT_FROM_UUID),
                    to: $this->account_uuid(self::ACCOUNT_TO_UUID),
                    money: $money = $this->money(10),
                    hash: $this->generateHash($money)
                ),
            ]);
    }

    #[Test]
    public function throws_exception_on_invalid_transfer_hash(): void
    {
        $initialMoney = $this->money(10);
        $validHash = $this->generateHash($initialMoney);
        $this->resetHash($validHash->getHash());

        TransferAggregate::fake(self::TRANSFER_UUID)
            ->given([
                new MoneyTransferred(
                    from: $this->account_uuid(self::ACCOUNT_FROM_UUID),
                    to: $this->account_uuid(self::ACCOUNT_TO_UUID),
                    money: $initialMoney,
                    hash: $validHash
                ),
            ])
            ->when(
                function (
                    TransferAggregate $transfers
                ): void {
                    $this->assertExceptionThrown(
                        function () use ($transfers) {
                            $transfers->applyMoneyTransferred(
                                new MoneyTransferred(
                                    from: $this->account_uuid(self::ACCOUNT_FROM_UUID),
                                    to: $this->account_uuid(self::ACCOUNT_TO_UUID),
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

        TransferAggregate::fake(self::TRANSFER_UUID)
            ->given([
                new MoneyTransferred(
                    from: $this->account_uuid(self::ACCOUNT_FROM_UUID),
                    to: $this->account_uuid(self::ACCOUNT_TO_UUID),
                    money: $initialMoney,
                    hash: $validHash
                ),
            ])
            ->when(
                function (
                    TransferAggregate $transfers
                ): void {
                    $this->assertExceptionThrown(
                        function () use ($transfers) {
                            $transfers->recordThat(
                                new MoneyTransferred(
                                    from: $this->account_uuid(self::ACCOUNT_FROM_UUID),
                                    to: $this->account_uuid(self::ACCOUNT_TO_UUID),
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

    private function account_uuid(string $uuid): AccountUuid
    {
        return hydrate(AccountUuid::class, ['uuid' => $uuid]);
    }

    private function hash(?string $hash = ''): Hash
    {
        return hydrate(
            Hash::class,
            ['hash' => hash(self::HASH_ALGORITHM, $hash)]
        );
    }
}
