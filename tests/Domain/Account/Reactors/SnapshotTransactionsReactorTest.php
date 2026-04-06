<?php

declare(strict_types=1);

namespace Tests\Domain\Account\Reactors;

use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Events\TransactionThresholdReached;
use App\Domain\Account\Reactors\SnapshotTransactionsReactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SnapshotTransactionsReactorTest extends TestCase
{
    private const string ACCOUNT_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const string ACCOUNT_NAME = 'fake-account';

    #[Test]
    public function fires_transaction_threshold_reached_event_when_transactions_threshold_is_met(): void
    {
        TransactionAggregate::fake(self::ACCOUNT_UUID)
            ->when(
                function (
                    TransactionAggregate $transactions
                ): void {
                    for (
                        $i = 0; $i <=
                                TransactionAggregate::COUNT_THRESHOLD;
                        $i++
                    ) {
                        $transactions->credit(
                            $this->money(10)
                        );
                    }
                }
            )
            ->assertEventRecorded(
                new TransactionThresholdReached()
            );
    }

    #[Test]
    public function triggers_snapshot_on_transactions_threshold_reached(): void
    {
        // Create a mock for the TransactionAggregate using PHPUnit's mock builder
        $aggregateMock = $this->createMock(TransactionAggregate::class);

        // Set the expectation that 'loadUuid' is called with ACCOUNT_UUID and returns the mock itself
        $aggregateMock->expects($this->once())
            ->method('loadUuid')
            ->with(self::ACCOUNT_UUID)
            ->willReturnSelf();

        // Set the expectation that 'snapshot' method is called exactly once
        $aggregateMock->expects($this->once())
            ->method('snapshot');

        // Inject the mocked TransactionAggregate into the reactor
        $reactor = new SnapshotTransactionsReactor($aggregateMock);

        // Dispatch the event and call the reactor's handler
        $reactor->onTransactionThresholdReached(
            (new TransactionThresholdReached())->setAggregateRootUuid(
                self::ACCOUNT_UUID
            )
        );
    }

    private function money(int $amount): Money
    {
        return hydrate(Money::class, ['amount' => $amount]);
    }
}
