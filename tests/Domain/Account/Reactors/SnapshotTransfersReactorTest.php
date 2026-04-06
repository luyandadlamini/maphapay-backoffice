<?php

declare(strict_types=1);

namespace Tests\Domain\Account\Reactors;

use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\Aggregates\TransferAggregate;
use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Events\TransferThresholdReached;
use App\Domain\Account\Reactors\SnapshotTransfersReactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SnapshotTransfersReactorTest extends TestCase
{
    private const string TRANSFER_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const string ACCOUNT_FROM_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeeb';

    private const string ACCOUNT_TO_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeec';

    private const string ACCOUNT_NAME = 'fake-account';

    #[Test]
    public function fires_transaction_threshold_reached_event_when_transfers_threshold_is_met(): void
    {
        TransferAggregate::fake(self::TRANSFER_UUID)
            ->when(
                function (
                    TransferAggregate $transfers
                ): void {
                    for (
                        $i = 0; $i <=
                                TransferAggregate::COUNT_THRESHOLD;
                        $i++
                    ) {
                        $transfers->transfer(
                            from: $this->account_uuid(
                                self::ACCOUNT_FROM_UUID
                            ),
                            to: $this->account_uuid(
                                self::ACCOUNT_TO_UUID
                            ),
                            money: $this->money(10)
                        );
                    }
                }
            )
            ->assertEventRecorded(
                new TransferThresholdReached()
            );
    }

    #[Test]
    public function triggers_snapshot_on_transfers_threshold_reached(): void
    {
        // Create a mock for the TransactionAggregate using PHPUnit's mock builder
        $aggregateMock = $this->createMock(TransferAggregate::class);

        // Set the expectation that 'loadUuid' is called with ACCOUNT_UUID and returns the mock itself
        $aggregateMock->expects($this->once())
            ->method('loadUuid')
            ->with(self::TRANSFER_UUID)
            ->willReturnSelf();

        // Set the expectation that 'snapshot' method is called exactly once
        $aggregateMock->expects($this->once())
            ->method('snapshot');

        // Inject the mocked TransferAggregate into the reactor
        $reactor = new SnapshotTransfersReactor($aggregateMock);

        // Dispatch the event and call the reactor's handler
        $reactor->onTransferThresholdReached(
            (new TransferThresholdReached())->setAggregateRootUuid(
                self::TRANSFER_UUID
            )
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
}
